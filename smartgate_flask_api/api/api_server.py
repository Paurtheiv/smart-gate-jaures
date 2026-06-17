"""
SmartGate V4 - API Server
--------------------------
Port 5000 — Communication ESP32 ↔ Raspberry Pi
"""
import time
import threading
import requests
from flask import Flask, request, jsonify
from database.db_manager import DatabaseManager
from config.config import API_HOST, API_PORT, API_KEY

app = Flask(__name__)
db  = DatabaseManager()

last_rfid        = None
last_fingerprint = None
last_access      = {}
enroll_requested = False


# ══════════════════════════════════════════════════
# THREAD DE FOND — surveille les expirations
# ══════════════════════════════════════════════════
def background_monitor():
    while True:
        try:
            n = db.check_expired_cards_log()
            if n > 0:
                print(f"⏰ {n} carte(s) expirée(s) loggée(s)")
        except Exception as e:
            print(f"⚠️ Thread : {e}")
        time.sleep(60)

threading.Thread(target=background_monitor, daemon=True).start()


# ══════════════════════════════════════════════════
# TEST
# ══════════════════════════════════════════════════
@app.route("/")
def home():
    return jsonify({"system": "SmartGate V4", "status": "running"})


# ══════════════════════════════════════════════════
# ACCÈS PRINCIPAL — appelé par ESP32
# ══════════════════════════════════════════════════
@app.route("/api/access")
def access():
    global last_rfid, last_fingerprint, last_access

    access_type = request.args.get("type")
    access_id   = request.args.get("id")
    key         = request.args.get("key")

    if key != API_KEY:
        return jsonify({"status": "error", "message": "Unauthorized"}), 403
    if not access_type or not access_id:
        return jsonify({"status": "error", "message": "Paramètres manquants"}), 400
    if access_type not in ("rfid", "fingerprint"):
        return jsonify({"status": "error", "message": "Type invalide"}), 400

    # Normaliser RFID en majuscules
    if access_type == "rfid":
        access_id = access_id.upper().strip()
        last_rfid = access_id
    else:
        last_fingerprint = access_id

    # Recherche utilisateur
    user = (db.get_user_by_rfid(access_id) if access_type == "rfid"
            else db.get_user_by_fingerprint(access_id))

    result = {}

    if user and user.get("rfid_blocked") and access_type == "rfid":
        # ── RFID bloqué ───────────────────────────
        blocked = user.get("rfid_blocked")
        note = ("RFID bloqué définitivement — contactez l'administration"
                if blocked == 2
                else "Carte principale bloquée — utilisez votre carte temporaire")
        result = {"status": "DENIED", "reason": note}
        db.log_access(user["id"], access_type, "DENIED", "GATE_1", note=note)

    elif user and user.get("rfid_blocked") and access_type == "fingerprint":
        # ── Empreinte OK même si RFID bloqué ──────
        result = {
            "status": "AUTHORIZED",
            "user_id": user["id"],
            "name":   user["name"],
            "class":  user["student_class"],
            "photo":  user["photo_filename"],
        }
        db.log_access(user["id"], access_type, "AUTHORIZED", "GATE_1",
                      note="Accès par empreinte (RFID bloqué)")

    elif user:
        # ── Accès normal ──────────────────────────
        result = {
            "status":  "AUTHORIZED",
            "user_id": user["id"],
            "name":    user["name"],
            "class":   user["student_class"],
            "photo":   user["photo_filename"],
        }
        db.log_access(user["id"], access_type, "AUTHORIZED", "GATE_1")

    elif access_type == "rfid":
        # ── Vérifier carte temporaire ─────────────
        temp = db.get_temporary_card(access_id)
        if temp:
            result = {
                "status": "AUTHORIZED",
                "name":   temp["student_name"] + " (carte temp.)",
                "class":  temp.get("student_class") or "",
                "photo":  temp.get("photo_filename"),
            }
            db.log_access(temp.get("user_id"), access_type,
                          "AUTHORIZED", "GATE_1", note="Carte temporaire")
        else:
            result = {"status": "DENIED"}
            db.log_access(None, access_type, "DENIED", "GATE_1",
                          note="Badge inconnu")
    else:
        # ── Empreinte inconnue ────────────────────
        result = {"status": "DENIED"}
        db.log_access(None, access_type, "DENIED", "GATE_1",
                      note="Empreinte inconnue — élève supprimé ou non enregistré")

    result["ts"] = time.time()
    last_access  = result

    try:
        requests.post("http://127.0.0.1:8000/update_access",
                      json=result, timeout=1)
    except Exception:
        pass

    return jsonify(result)


# ══════════════════════════════════════════════════
# TERMINAL
# ══════════════════════════════════════════════════
@app.route("/api/last_access")
def get_last_access():
    return jsonify(last_access)


# ══════════════════════════════════════════════════
# SCAN RFID / EMPREINTE
# ══════════════════════════════════════════════════
@app.route("/api/last_rfid")
def get_last_rfid():
    global last_rfid
    val = last_rfid; last_rfid = None
    return jsonify({"rfid": val})

@app.route("/api/last_fingerprint")
def get_last_fingerprint():
    global last_fingerprint
    val = last_fingerprint; last_fingerprint = None
    return jsonify({"fingerprint": val})

@app.route("/api/clear_rfid")
def clear_rfid():
    global last_rfid; last_rfid = None
    return jsonify({"ok": True})

@app.route("/api/clear_fingerprint")
def clear_fingerprint():
    global last_fingerprint; last_fingerprint = None
    return jsonify({"ok": True})


# ══════════════════════════════════════════════════
# ENROLLMENT
# ══════════════════════════════════════════════════
@app.route("/api/trigger_enroll")
def trigger_enroll():
    global enroll_requested
    enroll_requested = True
    return jsonify({"ok": True})

@app.route("/api/enroll_request")
def enroll_request_route():
    global enroll_requested
    val = enroll_requested; enroll_requested = False
    return jsonify({"enroll": val})


# ══════════════════════════════════════════════════
# SUPPRESSION EMPREINTE (RGPD)
# ══════════════════════════════════════════════════
@app.route("/api/delete_fingerprint_request")
def delete_fingerprint_request():
    item = db.get_next_fingerprint_to_delete()
    if item:
        return jsonify({
            "delete":         True,
            "queue_id":       item["id"],
            "fingerprint_id": item["fingerprint_id"]
        })
    return jsonify({"delete": False})

@app.route("/api/delete_fingerprint_done")
def delete_fingerprint_done():
    queue_id = request.args.get("queue_id")
    if not queue_id:
        return jsonify({"ok": False}), 400
    db.confirm_fingerprint_deleted(int(queue_id))
    return jsonify({"ok": True})


# ══════════════════════════════════════════════════
# LANCEMENT
# ══════════════════════════════════════════════════
def start_server():
    print("=" * 40)
    print("  SmartGate V4 — API Server")
    print("  Port 5000")
    print("=" * 40)
    app.run(host=API_HOST, port=API_PORT, debug=False)

if __name__ == "__main__":
    start_server()
