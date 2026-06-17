"""
SmartGate V5 - Gestionnaire de base de données MySQL
-----------------------------------------------------
MIGRÉ vers base unifiée smartgate_db (07/05/2026)

Tables :
  UTILISATEURS          (anciennement users)
  SG_ACCESS_LOGS        (anciennement access_logs)
  SG_SYSTEM_EVENTS      (anciennement system_events)
  SG_TEMPORARY_CARDS    (anciennement temporary_cards)
  SG_FINGERPRINT_DELETE_QUEUE (anciennement fingerprint_delete_queue)

Colonnes UTILISATEURS :
  nom + prenom  (anciennement name — alias CONCAT(prenom,' ',nom) AS name)
  classe        (anciennement student_class — alias AS student_class)
  uid_badge     (anciennement rfid_uid — alias AS rfid_uid)

Les méthodes gardent les mêmes signatures pour ne pas casser api_server.py
"""
import mysql.connector
from mysql.connector import Error
from config.config import (DB_HOST, DB_PORT, DB_NAME,
                           DB_USER, DB_PASSWORD, MAX_RENEWALS)


class DatabaseManager:

    def _connect(self):
        return mysql.connector.connect(
            host=DB_HOST, port=DB_PORT, database=DB_NAME,
            user=DB_USER, password=DB_PASSWORD, charset="utf8mb4"
        )

    def _fetchall(self, query, params=()):
        conn = self._connect()
        cur  = conn.cursor(dictionary=True)
        cur.execute(query, params)
        rows = cur.fetchall()
        cur.close(); conn.close()
        return rows

    def _fetchone(self, query, params=()):
        conn = self._connect()
        cur  = conn.cursor(dictionary=True)
        cur.execute(query, params)
        row = cur.fetchone()
        cur.close(); conn.close()
        return row

    def _execute(self, query, params=()):
        conn = self._connect()
        cur  = conn.cursor()
        cur.execute(query, params)
        conn.commit()
        lid = cur.lastrowid
        cur.close(); conn.close()
        return lid

    def _get_uid_badge(self, user_id):
        """Retourne uid_badge depuis UTILISATEURS.id — utilisé pour les FK logiques."""
        row = self._fetchone(
            "SELECT uid_badge FROM UTILISATEURS WHERE id=%s", (user_id,)
        )
        return row["uid_badge"] if row else None

    @staticmethod
    def _split_name(name):
        """'Prenom NOM' → (prenom, nom). Gère noms composés."""
        parts = name.strip().split(' ', 1)
        prenom = parts[0]
        nom    = parts[1] if len(parts) > 1 else ""
        return prenom, nom

    # ══════════════════════════════════════
    # UTILISATEURS
    # ── Alias SQL pour compatibilité api_server.py ──────────────
    #    CONCAT(prenom,' ',nom) AS name
    #    classe                 AS student_class
    #    uid_badge              AS rfid_uid
    # ══════════════════════════════════════

    def get_all_users(self):
        return self._fetchall("""
            SELECT id,
                   CONCAT(prenom, ' ', nom)  AS name,
                   classe                    AS student_class,
                   uid_badge                 AS rfid_uid,
                   fingerprint_id,
                   photo_filename,
                   rfid_blocked,
                   renewal_count,
                   created_at
            FROM UTILISATEURS
            WHERE role = 'ELEVE'
            ORDER BY nom, prenom
        """)

    def get_user_by_id(self, user_id):
        return self._fetchone("""
            SELECT id,
                   CONCAT(prenom, ' ', nom)  AS name,
                   classe                    AS student_class,
                   uid_badge                 AS rfid_uid,
                   fingerprint_id,
                   photo_filename,
                   rfid_blocked,
                   renewal_count,
                   created_at,
                   fingerprint_model
            FROM UTILISATEURS WHERE id=%s
        """, (user_id,))

    def get_user_by_rfid(self, rfid_uid):
        if not rfid_uid:
            return None
        rfid_uid = rfid_uid.upper().strip()
        return self._fetchone("""
            SELECT id,
                   CONCAT(prenom, ' ', nom)  AS name,
                   classe                    AS student_class,
                   uid_badge                 AS rfid_uid,
                   photo_filename,
                   rfid_blocked
            FROM UTILISATEURS
            WHERE UPPER(uid_badge)=%s
        """, (rfid_uid,))

    def get_user_by_fingerprint(self, fingerprint_id):
        if fingerprint_id is None:
            return None
        return self._fetchone("""
            SELECT id,
                   CONCAT(prenom, ' ', nom)  AS name,
                   classe                    AS student_class,
                   uid_badge                 AS rfid_uid,
                   photo_filename,
                   rfid_blocked
            FROM UTILISATEURS WHERE fingerprint_id=%s
        """, (fingerprint_id,))

    def search_users(self, query):
        like = f"%{query}%"
        return self._fetchall("""
            SELECT id,
                   CONCAT(prenom, ' ', nom)  AS name,
                   classe                    AS student_class,
                   uid_badge                 AS rfid_uid,
                   fingerprint_id,
                   photo_filename,
                   rfid_blocked,
                   renewal_count
            FROM UTILISATEURS
            WHERE CONCAT(prenom, ' ', nom) LIKE %s
               OR UPPER(uid_badge) = %s
            ORDER BY nom, prenom LIMIT 10
        """, (like, query.upper()))

    def add_user(self, name, student_class,
                 rfid_uid=None, fingerprint_id=None, photo_filename=None):
        if rfid_uid:
            rfid_uid = rfid_uid.upper().strip()
        prenom, nom = self._split_name(name)
        new_id = self._execute("""
            INSERT INTO UTILISATEURS
            (prenom, nom, classe, uid_badge, fingerprint_id, photo_filename, role)
            VALUES (%s, %s, %s, %s, %s, %s, 'ELEVE')
        """, (prenom, nom, student_class, rfid_uid, fingerprint_id, photo_filename))
        self.log_event("USER_ADDED",
                       f"Élève ajouté : {name} ({student_class})",
                       user_id=new_id)
        return new_id

    def update_user(self, user_id, name, student_class,
                    rfid_uid=None, fingerprint_id=None, photo_filename=None):
        if rfid_uid:
            rfid_uid = rfid_uid.upper().strip()
        prenom, nom = self._split_name(name)
        if photo_filename:
            self._execute("""
                UPDATE UTILISATEURS
                SET prenom=%s, nom=%s, classe=%s,
                    uid_badge=%s, fingerprint_id=%s, photo_filename=%s
                WHERE id=%s
            """, (prenom, nom, student_class,
                  rfid_uid, fingerprint_id, photo_filename, user_id))
        else:
            self._execute("""
                UPDATE UTILISATEURS
                SET prenom=%s, nom=%s, classe=%s,
                    uid_badge=%s, fingerprint_id=%s
                WHERE id=%s
            """, (prenom, nom, student_class, rfid_uid, fingerprint_id, user_id))
        self.log_event("USER_UPDATED",
                       f"Élève modifié : {name}", user_id=user_id)

    def delete_user(self, user_id):
        user = self.get_user_by_id(user_id)
        fid  = user["fingerprint_id"] if user else None
        name = user["name"] if user else "Inconnu"
        self.log_event("USER_DELETED",
                       f"Élève supprimé : {name}",
                       extra=f"ID={user_id}, FID={fid}")
        self._execute("DELETE FROM UTILISATEURS WHERE id=%s", (user_id,))
        return fid

    # ══════════════════════════════════════
    # BLOCAGE RFID
    # ══════════════════════════════════════

    def admin_unblock_rfid(self, user_id):
        conn = self._connect()
        cur  = conn.cursor(dictionary=True)
        try:
            cur.execute("""
                SELECT CONCAT(prenom,' ',nom) AS name, rfid_blocked
                FROM UTILISATEURS WHERE id=%s
            """, (user_id,))
            row = cur.fetchone()
            if not row or row["rfid_blocked"] != 1:
                return False
            name = row["name"]
            cur.execute("""
                UPDATE UTILISATEURS SET rfid_blocked=0, renewal_count=0
                WHERE id=%s
            """, (user_id,))
            # Supprimer les cartes temp liées via uid_badge
            uid = self._get_uid_badge(user_id)
            if uid:
                cur.execute(
                    "DELETE FROM SG_TEMPORARY_CARDS WHERE uid_badge=%s", (uid,)
                )
            conn.commit()
        finally:
            cur.close(); conn.close()
        self.log_event("RFID_UNBLOCKED_MANUAL",
                       f"RFID débloqué manuellement : {name}",
                       user_id=user_id)
        return True

    # ══════════════════════════════════════
    # SURVEILLANCE EXPIRATION (thread fond)
    # ══════════════════════════════════════

    def check_expired_cards_log(self):
        conn = self._connect()
        cur  = conn.cursor(dictionary=True)
        try:
            cur.execute("""
                SELECT tc.id, tc.student_name, tc.uid_badge,
                       tc.temporary_uid, tc.expiration_time,
                       u.id AS user_id
                FROM SG_TEMPORARY_CARDS tc
                LEFT JOIN UTILISATEURS u ON u.uid_badge = tc.uid_badge
                WHERE tc.expiration_time <= NOW()
                AND tc.expiration_time >= NOW() - INTERVAL 2 MINUTE
                AND NOT EXISTS (
                    SELECT 1 FROM SG_SYSTEM_EVENTS se
                    WHERE se.event_type = 'TEMP_CARD_EXPIRED'
                    AND se.extra_data LIKE CONCAT('%UID=', tc.temporary_uid, '%')
                )
            """)
            expired = cur.fetchall()
            for c in expired:
                cur.execute("""
                    INSERT INTO SG_SYSTEM_EVENTS
                    (event_type, description, uid_badge, extra_data)
                    VALUES ('TEMP_CARD_EXPIRED', %s, %s, %s)
                """, (
                    f"Carte temporaire expirée pour {c['student_name']} "
                    f"— RFID toujours bloqué",
                    c["uid_badge"],
                    f"UID={c['temporary_uid']}, expirée={c['expiration_time']}"
                ))
            conn.commit()
            return len(expired)
        finally:
            cur.close(); conn.close()

    # ══════════════════════════════════════
    # FILE SUPPRESSION EMPREINTES (RGPD)
    # ══════════════════════════════════════

    def queue_fingerprint_delete(self, fingerprint_id):
        if fingerprint_id is None:
            return
        self._execute("""
            INSERT IGNORE INTO SG_FINGERPRINT_DELETE_QUEUE (fingerprint_id)
            VALUES (%s)
        """, (fingerprint_id,))

    def get_next_fingerprint_to_delete(self):
        return self._fetchone("""
            SELECT id, fingerprint_id FROM SG_FINGERPRINT_DELETE_QUEUE
            ORDER BY id ASC LIMIT 1
        """)

    def confirm_fingerprint_deleted(self, queue_id):
        self._execute(
            "DELETE FROM SG_FINGERPRINT_DELETE_QUEUE WHERE id=%s", (queue_id,)
        )

    # ══════════════════════════════════════
    # LOGS D'ACCÈS
    # ══════════════════════════════════════

    def log_access(self, user_id, method, status, terminal, note=None):
        """
        user_id → récupère uid_badge + nom + classe depuis UTILISATEURS.
        Dénormalise student_name + student_class dans SG_ACCESS_LOGS.
        """
        uid = None
        student_name  = None
        student_class = None
        if user_id:
            row = self._fetchone("""
                SELECT uid_badge,
                       CONCAT(prenom,' ',nom) AS name,
                       classe
                FROM UTILISATEURS WHERE id=%s
            """, (user_id,))
            if row:
                uid           = row["uid_badge"]
                student_name  = row["name"]
                student_class = row["classe"]
        self._execute("""
            INSERT INTO SG_ACCESS_LOGS
            (uid_badge, student_name, student_class,
             authentication_method, access_status, terminal, note)
            VALUES (%s, %s, %s, %s, %s, %s, %s)
        """, (uid, student_name, student_class, method, status, terminal, note))

    def get_access_logs(self, limit=100, date_from=None, date_to=None,
                        status_filter=None, method_filter=None):
        where  = []
        params = []
        if date_from:
            where.append("al.timestamp >= %s")
            params.append(date_from + " 00:00:00")
        if date_to:
            where.append("al.timestamp <= %s")
            params.append(date_to + " 23:59:59")
        if status_filter:
            where.append("al.access_status = %s")
            params.append(status_filter)
        if method_filter:
            where.append("al.authentication_method = %s")
            params.append(method_filter)
        # student_name et student_class déjà dénormalisés dans SG_ACCESS_LOGS
        sql = """
            SELECT COALESCE(al.student_name, '— Inconnu —') AS name,
                   al.authentication_method, al.access_status,
                   al.terminal, al.note, al.timestamp
            FROM SG_ACCESS_LOGS al
        """
        if where:
            sql += " WHERE " + " AND ".join(where)
        sql += " ORDER BY al.timestamp DESC LIMIT %s"
        params.append(limit)
        return self._fetchall(sql, tuple(params))

    def get_stats_today(self):
        stats = {}
        stats["total_users"]       = self._fetchone(
            "SELECT COUNT(*) AS n FROM UTILISATEURS WHERE role='ELEVE'")["n"]
        stats["today_authorized"]  = self._fetchone("""
            SELECT COUNT(*) AS n FROM SG_ACCESS_LOGS
            WHERE access_status='AUTHORIZED'
            AND DATE(timestamp)=CURDATE()""")["n"]
        stats["today_denied"]      = self._fetchone("""
            SELECT COUNT(*) AS n FROM SG_ACCESS_LOGS
            WHERE access_status='DENIED'
            AND DATE(timestamp)=CURDATE()""")["n"]
        stats["active_temp_cards"] = self._fetchone("""
            SELECT COUNT(*) AS n FROM SG_TEMPORARY_CARDS
            WHERE expiration_time > NOW()""")["n"]
        return stats

    # ══════════════════════════════════════
    # ÉVÉNEMENTS SYSTÈME
    # ══════════════════════════════════════

    def log_event(self, event_type, description,
                  user_id=None, extra=None):
        try:
            uid = self._get_uid_badge(user_id) if user_id else None
            self._execute("""
                INSERT INTO SG_SYSTEM_EVENTS
                (event_type, description, uid_badge, extra_data)
                VALUES (%s, %s, %s, %s)
            """, (event_type, description, uid, extra))
        except Exception as e:
            print(f"⚠️ log_event : {e}")

    def get_system_events(self, limit=200,
                          date_from=None, date_to=None):
        where  = []
        params = []
        if date_from:
            where.append("timestamp >= %s")
            params.append(date_from + " 00:00:00")
        if date_to:
            where.append("timestamp <= %s")
            params.append(date_to + " 23:59:59")
        sql = "SELECT * FROM SG_SYSTEM_EVENTS"
        if where:
            sql += " WHERE " + " AND ".join(where)
        sql += " ORDER BY timestamp DESC LIMIT %s"
        params.append(limit)
        return self._fetchall(sql, tuple(params))

    # ══════════════════════════════════════
    # CARTES TEMPORAIRES
    # ══════════════════════════════════════

    def create_temporary_card(self, user_id, student_name,
                              temporary_uid, expiration_time):
        temporary_uid = temporary_uid.upper().strip()
        uid_badge = self._get_uid_badge(user_id)
        conn = self._connect()
        cur  = conn.cursor(dictionary=True)
        try:
            # Vérifier badge pas dans UTILISATEURS
            cur.execute(
                "SELECT id, CONCAT(prenom,' ',nom) AS name "
                "FROM UTILISATEURS WHERE UPPER(uid_badge)=%s",
                (temporary_uid,)
            )
            owner = cur.fetchone()
            if owner:
                raise ValueError(
                    f"Ce badge appartient à : {owner['name']}. "
                    "Impossible de l'utiliser comme carte temporaire."
                )
            # Vérifier badge pas déjà carte temp active
            cur.execute("""
                SELECT student_name FROM SG_TEMPORARY_CARDS
                WHERE UPPER(temporary_uid)=%s
                AND expiration_time > NOW()
            """, (temporary_uid,))
            existing = cur.fetchone()
            if existing:
                raise ValueError(
                    f"Ce badge est déjà utilisé par : {existing['student_name']}."
                )
            # Créer la carte temp
            cur.execute("""
                INSERT INTO SG_TEMPORARY_CARDS
                (uid_badge, student_name, temporary_uid, expiration_time)
                VALUES (%s, %s, %s, %s)
            """, (uid_badge, student_name, temporary_uid, expiration_time))
            # Bloquer le badge principal
            cur.execute("""
                UPDATE UTILISATEURS
                SET rfid_blocked=1, renewal_count=renewal_count+1
                WHERE id=%s
            """, (user_id,))
            conn.commit()
        except Error:
            conn.rollback(); raise
        finally:
            cur.close(); conn.close()
        self.log_event("TEMP_CARD_CREATED",
                       f"Carte temporaire créée pour {student_name}",
                       user_id=user_id,
                       extra=f"UID={temporary_uid}, expire={expiration_time}")

    def renew_temporary_card(self, user_id, student_name, new_expiration):
        uid_badge = self._get_uid_badge(user_id)
        conn = self._connect()
        cur  = conn.cursor(dictionary=True)
        try:
            # Dernière carte (active ou expirée)
            cur.execute("""
                SELECT id, temporary_uid FROM SG_TEMPORARY_CARDS
                WHERE uid_badge=%s
                ORDER BY expiration_time DESC LIMIT 1
            """, (uid_badge,))
            card = cur.fetchone()
            if not card:
                raise ValueError("Aucune carte temporaire trouvée.")

            # Prolonger
            cur.execute("""
                UPDATE SG_TEMPORARY_CARDS SET expiration_time=%s WHERE id=%s
            """, (new_expiration, card["id"]))

            # Incrémenter renewal_count
            cur.execute("""
                UPDATE UTILISATEURS
                SET renewal_count=renewal_count+1 WHERE id=%s
            """, (user_id,))

            # Vérifier limite
            cur.execute(
                "SELECT renewal_count FROM UTILISATEURS WHERE id=%s", (user_id,)
            )
            new_count = cur.fetchone()["renewal_count"]
            if new_count >= MAX_RENEWALS:
                cur.execute(
                    "UPDATE UTILISATEURS SET rfid_blocked=2 WHERE id=%s",
                    (user_id,)
                )
            conn.commit()

            if new_count >= MAX_RENEWALS:
                self.log_event("RFID_BLOCKED_PERMANENT",
                    f"RFID bloqué définitivement : {student_name}",
                    user_id=user_id)
            self.log_event("TEMP_CARD_RENEWED",
                f"Carte renouvelée pour {student_name} ({new_count}/{MAX_RENEWALS})",
                user_id=user_id,
                extra=f"UID={card['temporary_uid']}, expire={new_expiration}")
        except Error:
            conn.rollback(); raise
        finally:
            cur.close(); conn.close()

    def get_temporary_card(self, uid):
        uid = uid.upper().strip()
        return self._fetchone("""
            SELECT tc.student_name,
                   tc.expiration_time,
                   u.id       AS user_id,
                   u.photo_filename,
                   u.classe   AS student_class
            FROM SG_TEMPORARY_CARDS tc
            LEFT JOIN UTILISATEURS u ON u.uid_badge = tc.uid_badge
            WHERE UPPER(tc.temporary_uid)=%s
            AND tc.expiration_time > NOW()
        """, (uid,))

    def get_active_temp_card_for_user(self, user_id):
        uid_badge = self._get_uid_badge(user_id)
        return self._fetchone("""
            SELECT id, temporary_uid, expiration_time
            FROM SG_TEMPORARY_CARDS
            WHERE uid_badge=%s AND expiration_time > NOW()
            ORDER BY id DESC LIMIT 1
        """, (uid_badge,))

    def delete_temp_card(self, card_id):
        conn = self._connect()
        cur  = conn.cursor(dictionary=True)
        try:
            cur.execute("""
                SELECT tc.uid_badge, tc.student_name, tc.temporary_uid,
                       u.id AS user_id
                FROM SG_TEMPORARY_CARDS tc
                LEFT JOIN UTILISATEURS u ON u.uid_badge = tc.uid_badge
                WHERE tc.id=%s
            """, (card_id,))
            row = cur.fetchone()
            cur.execute(
                "DELETE FROM SG_TEMPORARY_CARDS WHERE id=%s", (card_id,)
            )
            conn.commit()
        except Error:
            conn.rollback(); raise
        finally:
            cur.close(); conn.close()
        if row:
            self.log_event("TEMP_CARD_DELETED",
                f"Carte supprimée pour {row.get('student_name','?')} "
                "— RFID reste bloqué",
                user_id=row.get("user_id"),
                extra=f"UID={row.get('temporary_uid')}")
