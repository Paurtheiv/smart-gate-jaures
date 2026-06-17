/**
 * SmartGate V5 - Firmware ESP32 CPE (FINAL)
 * -------------------------------------------
 * Lycée Jean Jaurès — Zone CPE
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <SPI.h>
#include <MFRC522.h>
#include <Adafruit_Fingerprint.h>
#include <AESLib.h>
#include <ArduinoJson.h>

// ── WiFi ──────────────────────────────────────
const char* SSID     = "YOUR_WIFI_SSID";
const char* PASSWORD = "YOUR_WIFI_PASSWORD";

// ── API ───────────────────────────────────────
const String SERVER  = "http://172.20.10.13:5000";
const String API_KEY = "YOUR_API_KEY";

// ── RFID ──────────────────────────────────────
#define SS_PIN  5
#define RST_PIN 22
MFRC522 rfid(SS_PIN, RST_PIN);

// ── Empreinte ─────────────────────────────────
HardwareSerial mySerial(2);
#define FINGER_PASSWORD 0x4A4A3201
Adafruit_Fingerprint finger = Adafruit_Fingerprint(&mySerial, FINGER_PASSWORD);

// ── AES-256 ───────────────────────────────────
byte AES_KEY[32] = {
  0x53,0x6D,0x61,0x72,0x74,0x47,0x61,0x74,
  0x65,0x56,0x35,0x4A,0x4A,0x32,0x30,0x32,
  0x36,0x4C,0x79,0x63,0x65,0x65,0x4A,0x4A,
  0x41,0x72,0x67,0x65,0x6E,0x74,0x65,0x75
};
byte AES_IV[16] = {
  0x53,0x47,0x56,0x35,0x4A,0x4A,0x32,0x30,
  0x32,0x36,0x5A,0x31,0x45,0x4E,0x54,0x52
};
AESLib aesLib;

// ── Buffers globaux ───────────────────────────
static uint8_t modelRaw[512];
static uint8_t modelEnc[528];

// ── Timing ────────────────────────────────────
unsigned long lastPollTime = 0;
const unsigned long POLL_INTERVAL = 2000;

// ── Anti-bruteforce ───────────────────────────
int           fingerFailCount = 0;
const int     MAX_FAIL        = 5;
const long    LOCKOUT_MS      = 30000;
bool          fingerLocked    = false;
unsigned long lockUntil       = 0;
bool          enrollRequested = false;


// ═══════════════════════════════════════════════
// SETUP
// ═══════════════════════════════════════════════
void setup() {
  Serial.begin(115200);
  Serial.println("\n==== SmartGate V5 CPE ====");

  // WiFi
  WiFi.begin(SSID, PASSWORD);
  Serial.print("WiFi");
  while (WiFi.status() != WL_CONNECTED) { delay(400); Serial.print("."); }
  Serial.println("\n✅ WiFi : " + WiFi.localIP().toString());

  // RFID
  SPI.begin();
  rfid.PCD_Init();
  Serial.println("✅ RFID prêt");

  // Capteur empreinte — une seule vérification propre
  mySerial.begin(57600, SERIAL_8N1, 4, 2);
  finger.begin(57600);
  delay(100);

  if (finger.verifyPassword()) {
    Serial.println("✅ Capteur CPE déverrouillé");
  } else {
    // Premier démarrage → définir mot de passe
    Serial.println("⚙️ Initialisation capteur CPE...");
    Adafruit_Fingerprint def = Adafruit_Fingerprint(&mySerial, 0x00000000);
    def.begin(57600);
    delay(100);
    if (def.verifyPassword()) {
      def.setPassword(FINGER_PASSWORD);
      delay(500);
      if (finger.verifyPassword()) {
        Serial.println("✅ Capteur CPE sécurisé !");
      } else {
        Serial.println("❌ Erreur capteur CPE"); while(1);
      }
    } else {
      Serial.println("❌ Capteur CPE inaccessible"); while(1);
    }
  }

  // AES
  aesLib.set_paddingmode(paddingMode::CMS);
  Serial.println("✅ AES-256 initialisé");
  Serial.println("🚀 SmartGate V5 CPE prêt !");
  Serial.println("============================");
}


// ═══════════════════════════════════════════════
// LOOP
// ═══════════════════════════════════════════════
void loop() {

  // ── RFID ────────────────────────────────────
  if (rfid.PICC_IsNewCardPresent() && rfid.PICC_ReadCardSerial()) {
    String uid = "";
    for (byte i = 0; i < rfid.uid.size; i++) {
      if (rfid.uid.uidByte[i] < 0x10) uid += "0";
      uid += String(rfid.uid.uidByte[i], HEX);
    }
    uid.toUpperCase();
    Serial.println("🪪 RFID CPE: " + uid);
    sendToServer("rfid", uid);
    delay(2000);
  }

  // ── Empreinte ───────────────────────────────
  if (fingerLocked) {
    if (millis() >= lockUntil) {
      fingerLocked = false; fingerFailCount = 0;
      Serial.println("🔓 Capteur débloqué");
    }
  } else if (!enrollRequested) {
    int fid = getFingerprintID();
    if (fid > 0) {
      fingerFailCount = 0;
      Serial.println("🔏 Empreinte CPE ID: " + String(fid));
      sendToServer("fingerprint", String(fid));
      delay(2000);
    } else if (fid == -2) {
      fingerFailCount++;
      sendToServer("fingerprint", "0");
      if (fingerFailCount >= MAX_FAIL) {
        fingerLocked = true;
        lockUntil    = millis() + LOCKOUT_MS;
        Serial.println("🔒 Bloqué 30s");
      }
      delay(1000);
    }
  }

  // ── Poll toutes les 2s ──────────────────────
  if (millis() - lastPollTime >= POLL_INTERVAL) {
    lastPollTime = millis();
    checkEnrollRequest();
    checkDeleteRequest();
  }
}


// ═══════════════════════════════════════════════
// ENVOI AU SERVEUR
// ═══════════════════════════════════════════════
void sendToServer(String type, String value) {
  if (WiFi.status() != WL_CONNECTED) { Serial.println("❌ WiFi perdu !"); return; }
  HTTPClient http;
  http.setTimeout(5000);
  http.begin(SERVER + "/api/access?type=" + type + "&id=" + value
             + "&key=" + API_KEY + "&terminal=CPE_ZONE1");
  int code = http.GET();
  if (code > 0) Serial.println(http.getString());
  else          Serial.println("❌ HTTP: " + String(code));
  http.end();
  delay(100);
}


// ═══════════════════════════════════════════════
// RECONNAISSANCE EMPREINTE
// ═══════════════════════════════════════════════
int getFingerprintID() {
  uint8_t p = finger.getImage();
  if (p == FINGERPRINT_NOFINGER) return -1;
  if (p != FINGERPRINT_OK)       return -1;
  p = finger.image2Tz();
  if (p != FINGERPRINT_OK) return -1;
  p = finger.fingerSearch();
  if (p == FINGERPRINT_OK) return finger.fingerID;
  return -2;
}


// ═══════════════════════════════════════════════
// CHIFFREMENT AES-256
// ═══════════════════════════════════════════════
String encryptModel(uint8_t* model, uint16_t len) {
  uint16_t paddedLen = ((len + 15) / 16) * 16;
  uint8_t padded[paddedLen];
  memcpy(padded, model, len);
  memset(padded + len, 0, paddedLen - len);
  byte iv_copy[16];
  memcpy(iv_copy, AES_IV, 16);
  aesLib.encrypt(padded, paddedLen, modelEnc, AES_KEY, 256, iv_copy);
  String b64 = "";
  const char* chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
  for (uint16_t i = 0; i < paddedLen; i += 3) {
    uint8_t b0 = modelEnc[i];
    uint8_t b1 = (i+1 < paddedLen) ? modelEnc[i+1] : 0;
    uint8_t b2 = (i+2 < paddedLen) ? modelEnc[i+2] : 0;
    b64 += chars[b0 >> 2];
    b64 += chars[((b0 & 0x3) << 4) | (b1 >> 4)];
    b64 += (i+1 < paddedLen) ? chars[((b1 & 0xF) << 2) | (b2 >> 6)] : '=';
    b64 += (i+2 < paddedLen) ? chars[b2 & 0x3F] : '=';
  }
  return b64;
}


// ═══════════════════════════════════════════════
// EXTRACTION + ENVOI MODÈLE
// ═══════════════════════════════════════════════
void extractAndSendModel(int fingerprintId, int userId) {
  Serial.println("📤 Extraction modèle ID=" + String(fingerprintId));

  if (finger.loadModel(fingerprintId) != FINGERPRINT_OK) {
    Serial.println("❌ loadModel failed"); return;
  }
  if (finger.getModel() != FINGERPRINT_OK) {
    Serial.println("❌ getModel failed"); return;
  }

  // Parser les paquets UpChar → extraire UNIQUEMENT les data bytes
  uint16_t bytesRead = 0;
  unsigned long timeout = millis() + 5000;

  while (bytesRead < 512 && millis() < timeout) {
    if (!mySerial.available()) { delay(1); continue; }

    // Chercher header 0xEF 0x01
    if (mySerial.read() != 0xEF) continue;
    unsigned long t = millis() + 100;
    while (!mySerial.available() && millis() < t);
    if (!mySerial.available() || mySerial.read() != 0x01) continue;

    // Sauter 4 bytes adresse
    int skip = 4;
    while (skip > 0) {
      if (mySerial.available()) { mySerial.read(); skip--; }
    }

    // Lire PID
    while (!mySerial.available() && millis() < timeout);
    uint8_t pid = mySerial.read();

    // Lire LEN (2 bytes) → data = LEN - 2 (sans checksum)
    while (mySerial.available() < 2 && millis() < timeout) delay(1);
    uint8_t lenH = mySerial.read();
    uint8_t lenL = mySerial.read();
    uint16_t dataLen = ((uint16_t)lenH << 8 | lenL) - 2;

    // Lire DATA bytes → stocker dans modelRaw
    for (uint16_t i = 0; i < dataLen; i++) {
      while (!mySerial.available() && millis() < timeout) delay(1);
      if (mySerial.available()) {
        uint8_t d = mySerial.read();
        if (bytesRead < 512) modelRaw[bytesRead++] = d;
      }
    }

    // Sauter checksum (2 bytes)
    for (int i = 0; i < 2; i++) {
      while (!mySerial.available() && millis() < timeout) delay(1);
      if (mySerial.available()) mySerial.read();
    }

    if (pid == 0x08) break; // Dernier paquet
  }

  Serial.println("✅ " + String(bytesRead) + " bytes extraits");

  if (bytesRead < 256) {
    Serial.println("❌ Extraction insuffisante — abandon"); return;
  }

  // Chiffrer et envoyer
  String encrypted = encryptModel(modelRaw, 512);
  Serial.println("✅ AES-256 → " + String(encrypted.length()) + " chars");

  if (WiFi.status() != WL_CONNECTED) return;
  HTTPClient http;
  http.begin(SERVER + "/api/store_fingerprint_model");
  http.addHeader("Content-Type", "application/json");
  DynamicJsonDocument doc(2048);
  doc["user_id"]        = userId;
  doc["fingerprint_id"] = fingerprintId;
  doc["model"]          = encrypted;
  doc["key"]            = API_KEY;
  String payload;
  serializeJson(doc, payload);
  Serial.println("📦 Payload: " + String(payload.length()) + " bytes");
  int code = http.POST(payload);
  if (code > 0) Serial.println("📨 " + http.getString());
  else          Serial.println("❌ POST error: " + String(code));
  http.end();
}


// ═══════════════════════════════════════════════
// POLL ENROLLMENT — FIX user_id string→int
// ═══════════════════════════════════════════════
void checkEnrollRequest() {
  if (WiFi.status() != WL_CONNECTED) return;
  HTTPClient http;
  http.begin(SERVER + "/api/enroll_request?key=" + API_KEY);
  if (http.GET() != 200) { http.end(); return; }
  String body = http.getString();
  http.end();

  if (body.indexOf("\"enroll\":true") == -1) return;

  // ── FIX CRITIQUE : user_id peut être string ou int ──
  DynamicJsonDocument doc(256);
  deserializeJson(doc, body);

  int userId = 0;
  if (doc["user_id"].is<int>()) {
    userId = doc["user_id"].as<int>();
  } else if (doc["user_id"].is<const char*>()) {
    userId = String(doc["user_id"].as<const char*>()).toInt();
  } else {
    // Fallback parsing manuel
    int s = body.indexOf("\"user_id\":") + 10;
    if (s > 10) {
      String val = body.substring(s);
      val.replace("\"", "");
      val.replace("}", "");
      val.replace(",", "");
      val.trim();
      userId = val.toInt();
    }
  }

  Serial.println("🔏 Enrollment demandé");
  Serial.println("👤 USER ID = " + String(userId));

  if (userId <= 0) {
    Serial.println("❌ user_id invalide — annulé");
    return;
  }

  enrollRequested = true;
  int newId = enrollNewFinger();
  enrollRequested = false;

  if (newId > 0) {
    extractAndSendModel(newId, userId);
  }
}


// ═══════════════════════════════════════════════
// AUDIT LOG
// ═══════════════════════════════════════════════
void logAudit(String action, int userId, int fingerprintId) {
  if (WiFi.status() != WL_CONNECTED) return;
  HTTPClient http;
  http.begin(SERVER + "/api/audit_log");
  http.addHeader("Content-Type", "application/json");
  String payload = "{\"action\":\"" + action +
                   "\",\"user_id\":" + String(userId) +
                   ",\"fingerprint_id\":" + String(fingerprintId) +
                   ",\"terminal\":\"CPE_ZONE1\"" +
                   ",\"key\":\"" + API_KEY + "\"}";
  http.POST(payload);
  http.end();
}


// ═══════════════════════════════════════════════
// POLL SUPPRESSION (RGPD)
// ═══════════════════════════════════════════════
void checkDeleteRequest() {
  if (WiFi.status() != WL_CONNECTED) return;
  HTTPClient http;
  http.begin(SERVER + "/api/delete_fingerprint_request?key=" + API_KEY + "&terminal=CPE_ZONE1");
  if (http.GET() != 200) { http.end(); return; }
  String body = http.getString();
  http.end();
  if (body.indexOf("\"delete\":true") == -1) return;

  int fidStart = body.indexOf("\"fingerprint_id\":") + 17;
  int fidEnd   = body.indexOf(",", fidStart);
  if (fidEnd == -1) fidEnd = body.indexOf("}", fidStart);
  int qidStart = body.indexOf("\"queue_id\":") + 11;
  int qidEnd   = body.indexOf(",", qidStart);
  if (qidEnd == -1) qidEnd = body.indexOf("}", qidStart);

  int fingerprintId = body.substring(fidStart, fidEnd).toInt();
  int queueId       = body.substring(qidStart, qidEnd).toInt();

  Serial.println("🗑 Suppression CPE ID=" + String(fingerprintId));
  finger.deleteModel(fingerprintId);
  logAudit("MODEL_DELETED", 0, fingerprintId);

  HTTPClient h2;
  h2.begin(SERVER + "/api/delete_fingerprint_done?queue_id=" + String(queueId));
  h2.GET(); h2.end();
  Serial.println("✅ Supprimé (RGPD)");
}


// ═══════════════════════════════════════════════
// ENROLLMENT
// ═══════════════════════════════════════════════
int enrollNewFinger() {
  int newID = -1;
  for (int i = 1; i <= 127; i++) {
    if (finger.loadModel(i) != FINGERPRINT_OK) { newID = i; break; }
  }
  if (newID == -1) { Serial.println("❌ Capteur plein"); return -1; }
  Serial.println("→ Slot CPE: " + String(newID));

  Serial.println("→ Posez le doigt...");
  uint8_t p = FINGERPRINT_NOFINGER;
  while (p != FINGERPRINT_OK) { p = finger.getImage(); delay(200); }
  p = finger.image2Tz(1);
  if (p != FINGERPRINT_OK) { Serial.println("❌ Erreur 1"); return -1; }

  Serial.println("✅ 1ère OK — retirez");
  delay(2000);
  while (finger.getImage() != FINGERPRINT_NOFINGER) delay(200);

  Serial.println("→ Posez le même doigt...");
  p = FINGERPRINT_NOFINGER;
  while (p != FINGERPRINT_OK) { p = finger.getImage(); delay(200); }
  p = finger.image2Tz(2);
  if (p != FINGERPRINT_OK) { Serial.println("❌ Erreur 2"); return -1; }

  p = finger.createModel();
  if (p != FINGERPRINT_OK) { Serial.println("❌ Doigts différents"); return -1; }
  p = finger.storeModel(newID);
  if (p != FINGERPRINT_OK) { Serial.println("❌ Stockage"); return -1; }

  Serial.println("✅ Empreinte CPE enregistrée ID=" + String(newID));
  return newID;
}
