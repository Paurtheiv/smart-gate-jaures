# ⬛ Smart Gate Jaurès

> Système IoT de contrôle d'accès biométrique — Lycée Jean Jaurès, Argenteuil  
> Projet E6-IR · BTS CIEL A · Session 2026

[![Arduino](https://img.shields.io/badge/Arduino-ESP32-00979D?style=flat&logo=arduino)](https://www.arduino.cc/)
[![Python](https://img.shields.io/badge/Python-Flask-3776AB?style=flat&logo=python)](https://flask.palletsprojects.com/)
[![PHP](https://img.shields.io/badge/PHP-Apache-777BB4?style=flat&logo=php)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-MariaDB-4479A1?style=flat&logo=mysql)](https://mariadb.org/)
[![Security](https://img.shields.io/badge/Security-AES--256-red?style=flat)](https://en.wikipedia.org/wiki/Advanced_Encryption_Standard)

---

## 📋 Description

**Smart Gate Jaurès** est un système complet de contrôle d'accès biométrique développé pour le Lycée Jean Jaurès d'Argenteuil dans le cadre du BTS CIEL A (session 2026).

Le système permet l'identification des élèves par **badge RFID** ou **empreinte digitale** à l'entrée du lycée, avec :
- Validation/refus en temps réel avec feedback visuel (LEDs WS2812B) et sonore (buzzer)
- Chiffrement **AES-256** des gabarits d'empreintes stockés en base de données
- Interface web d'administration PHP avec dashboard temps réel
- Conformité **RGPD** (suppression physique des empreintes à la demande)
- Base de données **MySQL centralisée** pour 5 zones du lycée

**Zone couverte (Zone 1) :** Entrée principale du lycée

---

## 🏗️ Architecture du système

```
┌─────────────────────────────────────────────────────────────┐
│                    RASPBERRY PI (Zone 1)                     │
│                                                              │
│  ┌─────────────────┐    ┌──────────────────────────────┐   │
│  │  Flask API      │    │  Interface Web PHP           │   │
│  │  Port 5000      │    │  Apache2 · Port 80           │   │
│  │                 │    │                              │   │
│  │  /api/access    │    │  dashboard.php               │   │
│  │  /api/enroll    │    │  users.php (CRUD)            │   │
│  │  /api/logs      │    │  terminal.php (kiosque)      │   │
│  └────────┬────────┘    │  logs.php                    │   │
│           │              │  temporary_cards.php         │   │
│  ┌────────▼────────┐    └──────────────────────────────┘   │
│  │   MySQL DB      │                                         │
│  │  smartgate_db    │◄── AlwaysData (Cloud)                  │
│  │  9 tables       │                                         │
│  └─────────────────┘                                         │
└──────────────┬──────────────────────────────────────────────┘
               │ HTTP / REST API
       ┌───────┴────────┐
       ▼                ▼
┌─────────────┐  ┌─────────────┐
│  ESP32 CPE  │  │  ESP32 Porte│
│ (Enrollment)│  │(Identification)│
│             │  │             │
│ RFID MFRC522│  │ RFID MFRC522│
│ Adafruit FPS│  │ Adafruit FPS│
│ AES-256 enc │  │ AES-256 dec │
│             │  │ WS2812B LEDs│
│             │  │ Buzzer KY-006│
└─────────────┘  └─────────────┘
```

---

## 📁 Structure du projet

```
smart-gate-jaures/
│
├── 📂 smartgate_esp32_cpe-PPF/          # Firmware ESP32 — Module CPE (inscription)
│   └── smartgate_esp32_cpe-PPF.ino     # C++ Arduino : RFID + FPS + AES-256 + WiFi
│
├── 📂 smartgate_esp32_portesPF/         # Firmware ESP32 — Module Porte (identification)
│   └── smartgate_esp32_portesPF.ino    # C++ Arduino : RFID + FPS + LEDs + Buzzer
│
├── 📂 smartgate_v5/sg4/                 # Backend Raspberry Pi (Python)
│   ├── api/
│   │   └── api_server.py               # Flask REST API (port 5000)
│   ├── web/
│   │   ├── app.py                      # Flask web app (port 8000)
│   │   └── templates/                  # Jinja2 templates
│   ├── database/
│   │   ├── db_manager.py               # Gestionnaire MySQL
│   │   └── smartgate_v4_database.sql   # Schéma complet de la BDD
│   ├── config/
│   │   └── config.py                   # Configuration (voir config.example.py)
│   ├── start.sh                        # Lancement des services
│   └── stop.sh                         # Arrêt des services
│
└── 📂 smartgate_php_v4/                 # Interface Web PHP (Apache2, port 80)
    └── php/smartgate/
        ├── dashboard.php               # Dashboard temps réel (polling 3s)
        ├── users.php                   # CRUD élèves + capture webcam
        ├── terminal.php                # Kiosque public (noir, AUTORISÉ/REFUSÉ)
        ├── logs.php                    # Historique des accès
        ├── temporary_cards.php         # Gestion cartes temporaires
        ├── api/
        │   ├── logs.php                # API logs (JSON)
        │   ├── stats.php               # API statistiques
        │   └── proxy.php               # Proxy → Flask API
        ├── config/db.php               # Configuration BDD
        └── assets/
            ├── style.css               # CSS personnalisé
            └── camera_widget.js        # Capture photo webcam
```

---

## ⚙️ Technologies utilisées

| Composant | Technologie | Rôle |
|-----------|-------------|------|
| Microcontrôleur | ESP32 (WiFi) | Lecture RFID/biométrie, communication HTTP |
| Badge | RFID MFRC522 (SPI) | Identification par badge |
| Biométrie | Adafruit Fingerprint Sensor (UART) | 127 slots, empreintes digitales |
| Chiffrement | AES-256 (CBC) | Protection des gabarits biométriques |
| Feedback visuel | WS2812B NeoPixel (30 LEDs) | Vert = autorisé, Rouge = refusé |
| Feedback sonore | Buzzer KY-006 | Bip court = autorisé, long = refusé |
| API backend | Python Flask (port 5000) | Communication ESP32 ↔ Raspberry Pi |
| Web admin | PHP / Apache2 (port 80) | Interface CRUD, dashboard, kiosque |
| Base de données | MySQL / MariaDB | 9 tables, vues SQL, indexation |
| Hébergement BDD | AlwaysData (Cloud) | Base `smartgate_db` centralisée |
| OS | Raspberry Pi OS (Raspbian) | Serveur embarqué |

---

## 🗄️ Schéma de la base de données

```sql
users               -- Élèves : RFID, empreinte, photo, classe
access_logs         -- Historique de tous les accès (autorisés + refusés)
temporary_cards     -- Cartes temporaires (max 3 renouvellements)
system_events       -- Événements système (inscriptions, erreurs)
fingerprint_delete_queue  -- File RGPD : suppressions en attente
access_logs_archive -- Archive des anciens logs
system_events_archive    -- Archive des anciens événements

-- Vues SQL :
v_access_logs       -- Logs enrichis avec infos utilisateur
v_temporary_cards   -- Cartes temporaires actives
v_stats_today       -- Statistiques du jour
```

**Champ clé :** `rfid_blocked` (TINYINT) — `0` = actif, `1` = temporairement bloqué, `2` = définitivement bloqué (après 3 renouvellements de carte temporaire)

---

## 🔒 Sécurité

- **AES-256-CBC** : les gabarits d'empreintes sont chiffrés sur l'ESP32 CPE avant envoi et stockage. Déchiffrés uniquement sur l'ESP32 Porte au moment de la comparaison.
- **Anti-bruteforce** : 5 tentatives d'empreinte échouées → verrouillage 30 secondes
- **Authentification API** : clé API statique dans chaque requête ESP32
- **Multi-rôles** : admin (accès complet) vs CPE (accès restreint aux classes autorisées)
- **RGPD** : table `fingerprint_delete_queue` → l'ESP32 Porte confirme la suppression physique (polling toutes les 3s), puis le serveur supprime la BDD

---

## 🚀 Installation

### Raspberry Pi

```bash
# 1. Cloner le dépôt
git clone https://github.com/Paurtheiv/smart-gate-jaures.git
cd smart-gate-jaures

# 2. Installer les dépendances Python
pip install flask pymysql cryptography

# 3. Copier et configurer
cp smartgate_v5/sg4/config/config.example.py smartgate_v5/sg4/config/config.py
nano smartgate_v5/sg4/config/config.py  # Renseigner DB_HOST, DB_USER, DB_PASS

# 4. Importer la base de données
mysql -u root -p < smartgate_v5/sg4/database/smartgate_v4_database.sql

# 5. Démarrer les services
cd smartgate_v5/sg4
chmod +x start.sh
./start.sh
```

### ESP32 (Arduino IDE)

```
Bibliothèques requises :
- MFRC522 (RFID)
- Adafruit Fingerprint Sensor Library
- ArduinoJson
- Crypto (AES-256)
- FastLED (WS2812B — Porte uniquement)
```

1. Ouvrir `smartgate_esp32_cpe-PPF.ino` dans Arduino IDE
2. Configurer `WIFI_SSID`, `WIFI_PASS`, `SERVER_IP` dans le code
3. Flasher sur l'ESP32

---

## 👤 Auteur

**Paurtheiv Krishna Laxman**  
BTS CIEL A — Lycée Jean Jaurès, Argenteuil (95)  
Zone 1 : Entrée principale  
Fonctions : F1, F2, F3, F13, F14, F15, F16

📧 paurtheiv.laxman.fr@gmail.com  
🔗 [Portfolio](https://paurtheiv.github.io)  
🔗 [LinkedIn](https://linkedin.com/in/paurtheiv-krishna-laxman080068345)

### Équipe projet (5 zones)

| Étudiant | Zone |
|----------|------|
| Paurtheiv Krishna Laxman | Zone 1 — Entrée principale |
| Mekki | Zone 2 |
| Florian | Zone 3 |
| Walid | Zone 4 |
| Yoann | Zone 5 |

**Encadrants :** M. NGASSAM Flaubert · Mme LAKHAL Hanen · M. KOURKZI Mustapha

---

## 📄 Licence

Projet académique — BTS CIEL A, Session 2026  
Lycée Jean Jaurès, Argenteuil (95100)
