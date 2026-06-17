-- ============================================================
-- SmartGate V4 - Base de données MySQL/MariaDB
-- Lycée Jean Jaurès — Argenteuil
-- ============================================================
-- Installation :
--   sudo mysql -u root -p < smartgate_v4_database.sql
--
-- Connexion PHP :
--   host     : localhost
--   database : smartgate
--   user     : smartgate_user
--   password : SmartGate2026!
-- ============================================================

CREATE DATABASE IF NOT EXISTS smartgate
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'smartgate_user'@'localhost'
  IDENTIFIED BY 'SmartGate2026!';

GRANT ALL PRIVILEGES ON smartgate.* TO 'smartgate_user'@'localhost';
FLUSH PRIVILEGES;

USE smartgate;

-- ============================================================
-- TABLE : users
-- Contient tous les élèves enregistrés
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id              INT           AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100)  NOT NULL COMMENT 'Prénom + Nom',
    student_class   VARCHAR(50)   COMMENT 'Ex: BTS S2CIEL A',
    rfid_uid        VARCHAR(50)   UNIQUE COMMENT 'UID badge RFID principal (toujours en MAJUSCULES)',
    fingerprint_id  INT           UNIQUE COMMENT 'ID empreinte dans le capteur (1-127)',
    photo_filename  VARCHAR(200)  COMMENT 'Nom du fichier photo dans web/static/photos/',

    -- Gestion blocage RFID
    -- 0 = actif (normal)
    -- 1 = bloqué temporairement (carte temporaire active)
    -- 2 = bloqué DÉFINITIVEMENT (limite 3 renouvellements atteinte)
    rfid_blocked    TINYINT(1)    DEFAULT 0 COMMENT '0=actif, 1=bloqué temp, 2=bloqué définitif',

    -- Compteur renouvellements carte temporaire (max 3)
    renewal_count   INT           DEFAULT 0 COMMENT 'Nb renouvellements carte temp (max 3)',

    created_at      DATETIME      DEFAULT CURRENT_TIMESTAMP

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Élèves enregistrés dans le système SmartGate';


-- ============================================================
-- TABLE : access_logs
-- Historique de tous les passages à l'entrée
-- ============================================================
CREATE TABLE IF NOT EXISTS access_logs (
    id                    INT          AUTO_INCREMENT PRIMARY KEY,

    -- NULL si badge/empreinte inconnu
    user_id               INT          COMMENT 'ID élève (NULL si inconnu)',

    -- 'rfid' ou 'fingerprint'
    authentication_method VARCHAR(20)  COMMENT 'rfid ou fingerprint',

    -- 'AUTHORIZED' ou 'DENIED'
    access_status         VARCHAR(20)  COMMENT 'AUTHORIZED ou DENIED',

    terminal              VARCHAR(50)  DEFAULT 'GATE_1' COMMENT 'Identifiant du terminal',

    -- Informations supplémentaires sur le refus
    -- Ex: 'Badge inconnu', 'Carte principale bloquée', 'Carte temporaire'
    note                  VARCHAR(200) COMMENT 'Détail du résultat',

    timestamp             DATETIME     DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Logs de tous les accès à l entrée du lycée';


-- ============================================================
-- TABLE : temporary_cards
-- Cartes temporaires créées pour les élèves
-- (badge perdu, oublié, cassé)
-- ============================================================
CREATE TABLE IF NOT EXISTS temporary_cards (
    id              INT          AUTO_INCREMENT PRIMARY KEY,

    -- Élève concerné
    user_id         INT          COMMENT 'ID de l élève concerné',
    student_name    VARCHAR(100) NOT NULL COMMENT 'Nom de l élève (copie pour historique)',

    -- UID du badge temporaire (DIFFÉRENT du badge principal)
    temporary_uid   VARCHAR(50)  UNIQUE NOT NULL COMMENT 'UID du badge temporaire (MAJUSCULES)',

    expiration_time DATETIME     NOT NULL COMMENT 'Date/heure d expiration',
    created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Cartes temporaires actives ou expirées';


-- ============================================================
-- TABLE : system_events
-- Événements système (actions admin, alertes)
-- ============================================================
CREATE TABLE IF NOT EXISTS system_events (
    id          INT           AUTO_INCREMENT PRIMARY KEY,

    -- Type d'événement :
    -- USER_ADDED, USER_DELETED, USER_UPDATED
    -- TEMP_CARD_CREATED, TEMP_CARD_RENEWED, TEMP_CARD_DELETED
    -- TEMP_CARD_EXPIRED
    -- RFID_BLOCKED_PERMANENT, RFID_UNBLOCKED_MANUAL
    event_type  VARCHAR(50)   NOT NULL COMMENT 'Type d événement',

    description TEXT          COMMENT 'Description lisible de l événement',
    user_id     INT           COMMENT 'ID élève concerné (si applicable)',
    extra_data  VARCHAR(500)  COMMENT 'Données supplémentaires (UID, dates...)',
    timestamp   DATETIME      DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Journal des actions admin et événements système';


-- ============================================================
-- TABLE : fingerprint_delete_queue
-- File d'attente pour suppression des empreintes du capteur
-- Conformité RGPD : quand un élève est supprimé,
-- son empreinte doit être effacée physiquement du capteur ESP32
-- ============================================================
CREATE TABLE IF NOT EXISTS fingerprint_delete_queue (
    id             INT      AUTO_INCREMENT PRIMARY KEY,
    fingerprint_id INT      NOT NULL COMMENT 'ID à supprimer du capteur',
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,

    -- Évite les doublons
    UNIQUE KEY uq_fid (fingerprint_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='File d attente suppression empreintes (RGPD)';


-- ============================================================
-- TABLE : access_logs_archive
-- Archive des logs d'accès (remplie automatiquement chaque nuit)
-- Les logs de la veille sont déplacés ici à 00h00
-- ============================================================
CREATE TABLE IF NOT EXISTS access_logs_archive (
    id                    INT,
    user_id               INT,
    authentication_method VARCHAR(20),
    access_status         VARCHAR(20),
    terminal              VARCHAR(50),
    note                  VARCHAR(200),
    timestamp             DATETIME,
    archive_date          DATE DEFAULT (CURDATE()) COMMENT 'Date d archivage'

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Archive des logs d accès (archivage automatique nuit)';


-- ============================================================
-- TABLE : system_events_archive
-- Archive des événements système
-- ============================================================
CREATE TABLE IF NOT EXISTS system_events_archive (
    id          INT,
    event_type  VARCHAR(50),
    description TEXT,
    user_id     INT,
    extra_data  VARCHAR(500),
    timestamp   DATETIME,
    archive_date DATE DEFAULT (CURDATE())

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Archive des événements système';


-- ============================================================
-- INDEX — pour accélérer les requêtes fréquentes
-- ============================================================
CREATE INDEX idx_users_rfid        ON users(rfid_uid);
CREATE INDEX idx_users_finger      ON users(fingerprint_id);
CREATE INDEX idx_users_blocked     ON users(rfid_blocked);
CREATE INDEX idx_logs_timestamp    ON access_logs(timestamp);
CREATE INDEX idx_logs_status       ON access_logs(access_status);
CREATE INDEX idx_logs_user         ON access_logs(user_id);
CREATE INDEX idx_temp_uid          ON temporary_cards(temporary_uid);
CREATE INDEX idx_temp_expiration   ON temporary_cards(expiration_time);
CREATE INDEX idx_temp_user         ON temporary_cards(user_id);
CREATE INDEX idx_events_ts         ON system_events(timestamp);
CREATE INDEX idx_events_type       ON system_events(event_type);


-- ============================================================
-- VUES utiles pour PHP
-- ============================================================

-- Vue : logs avec nom élève (évite le JOIN à chaque requête PHP)
CREATE OR REPLACE VIEW v_access_logs AS
SELECT
    al.id,
    COALESCE(u.name, '— Inconnu —')   AS student_name,
    COALESCE(u.student_class, '')      AS student_class,
    al.authentication_method,
    al.access_status,
    al.terminal,
    al.note,
    al.timestamp
FROM access_logs al
LEFT JOIN users u ON u.id = al.user_id
ORDER BY al.timestamp DESC;

-- Vue : cartes temporaires avec infos élève
CREATE OR REPLACE VIEW v_temporary_cards AS
SELECT
    tc.id,
    tc.user_id,
    tc.student_name,
    tc.temporary_uid,
    tc.expiration_time,
    tc.created_at,
    CASE
        WHEN tc.expiration_time > NOW() THEN 'ACTIVE'
        ELSE 'EXPIRÉE'
    END AS status,
    u.rfid_uid        AS rfid_principal,
    u.rfid_blocked,
    u.renewal_count
FROM temporary_cards tc
LEFT JOIN users u ON u.id = tc.user_id
ORDER BY tc.id DESC;

-- Vue : stats du jour
CREATE OR REPLACE VIEW v_stats_today AS
SELECT
    (SELECT COUNT(*) FROM users)                                          AS total_users,
    (SELECT COUNT(*) FROM access_logs
     WHERE access_status='AUTHORIZED' AND DATE(timestamp)=CURDATE())     AS today_authorized,
    (SELECT COUNT(*) FROM access_logs
     WHERE access_status='DENIED' AND DATE(timestamp)=CURDATE())         AS today_denied,
    (SELECT COUNT(*) FROM temporary_cards
     WHERE expiration_time > NOW())                                       AS active_temp_cards;


-- ============================================================
-- DONNÉES DE TEST (optionnel — commenter en production)
-- ============================================================
-- INSERT INTO users (name, student_class, rfid_uid, fingerprint_id)
-- VALUES ('Alice DUPONT', 'BTS S2CIEL A', 'A57BB489', 1);


-- ============================================================
SELECT '✅ SmartGate V4 — Base de données initialisée !' AS message;
SELECT 'Connexion PHP : smartgate_user@localhost/smartgate' AS info;
-- ============================================================
