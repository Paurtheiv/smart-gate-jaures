/**
 * SmartGate V5 - ESP32 PORTE (FINAL)
 * Lycée Jean Jaurès — Entrée Principale
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <SPI.h>
#include <MFRC522.h>
#include <Adafruit_Fingerprint.h>
#include <AESLib.h>
#include <Adafruit_NeoPixel.h>

// ══════════════════════════════════════════════
// CONFIG
// ══════════════════════════════════════════════
const char* SSID     = "YOUR_WIFI_SSID";
const char* PASSWORD = "YOUR_WIFI_PASSWORD";
const String SERVER  = "http://172.20.10.13:5000";
const String API_KEY = "YOUR_API_KEY";

// ══════════════════════════════════════════════
// RFID
// ══════════════════════════════════════════════
#define SS_PIN  5
#define RST_PIN 22
MFRC522 rfid(SS_PIN, RST_PIN);

// ══════════════════════════════════════════════
// EMPREINTE
// ══════════════════════════════════════════════
#define FINGER_PASSWORD 0x4A4A3200
HardwareSerial mySerial(2);
Adafruit_Fingerprint finger(&mySerial, FINGER_PASSWORD);

// ══════════════════════════════════════════════
// AES
// ══════════════════════════════════════════════
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

// ══════════════════════════════════════════════
// LEDs + BUZZER
// ══════════════════════════════════════════════
#define LED_PIN  15
#define NUM_LEDS 30
Adafruit_NeoPixel strip(NUM_LEDS, LED_PIN, NEO_GRB + NEO_KHZ800);
#define PIN_BUZZ 25

// ══════════════════════════════════════════════
// BUFFERS GLOBAUX
// ══════════════════════════════════════════════
static uint8_t modelBuffer[512];
static uint8_t encBuffer[700];

// ══════════════════════════════════════════════
// TIMERS
// ══════════════════════════════════════════════
unsigned long lastSync   = 0;
unsigned long lastDelete = 0;
const unsigned long SYNC_INT   = 30000;
const unsigned long DELETE_INT = 3000;


// ══════════════════════════════════════════════
// DÉCLARATIONS ANTICIPÉES
// ══════════════════════════════════════════════
void setLEDs(uint8_t r, uint8_t g, uint8_t b);
void bipCourt();
void bipLong();
void syncFingerprints();
void checkDelete();
String sendAccess(String type, String value);
void handleResult(String response);
int getFingerID();
bool decryptModel(const char* b64, uint8_t* out, uint16_t* outLen);
bool injectModel(uint8_t* data, uint16_t len, int slotId);


// ══════════════════════════════════════════════
// SETUP
// ══════════════════════════════════════════════
void setup() {
  Serial.begin(115200);
  Serial.println("\n==== SmartGate V5 PORTE ====");

  // Buzzer — KY-012 actif → juste HIGH/LOW
  pinMode(PIN_BUZZ, OUTPUT);
  digitalWrite(PIN_BUZZ, LOW);

  // LEDs — éteintes au démarrage (économise VCC pour capteur)
  strip.begin();
  strip.setBrightness(80);
  strip.show();

  // WiFi
  WiFi.begin(SSID, PASSWORD);
  Serial.print("WiFi");
  while (WiFi.status() != WL_CONNECTED) {
    delay(400); Serial.print(".");
  }
  Serial.println("\n✅ WiFi : " + WiFi.localIP().toString());

  // RFID
  SPI.begin();
  rfid.PCD_Init();
  Serial.println("✅ RFID prêt");

  // Capteur empreinte
  mySerial.begin(57600, SERIAL_8N1, 4, 2);
  finger.begin(57600);
  delay(200);

  // Vérification mot de passe capteur
  if (finger.verifyPassword()) {
    Serial.println("✅ Capteur Porte déverrouillé");
  } else {
    // Premier démarrage → définir le mot de passe
    Serial.println("⚙️ Initialisation capteur...");
    Adafruit_Fingerprint def(&mySerial, 0x00000000);
    def.begin(57600);
    delay(200);
    if (def.verifyPassword()) {
      def.setPassword(FINGER_PASSWORD);
      delay(500);
      if (finger.verifyPassword()) {
        Serial.println("✅ Capteur Porte sécurisé !");
      } else {
        Serial.println("❌ Capteur Porte erreur — vérifier câblage");
        while(1) { delay(1000); }
      }
    } else {
      //Serial.println("❌ Capteur inaccessible — vérifier câblage");
      //while(1) { delay(1000); }
      Serial.println("⚠️ Capteur empreinte non disponible — RFID seul");
    }
  }

  // AES
  aesLib.set_paddingmode(paddingMode::CMS);
  Serial.println("✅ AES-256 prêt");

  // Sync initiale
  Serial.println("🔄 Sync initiale...");
  syncFingerprints();

  Serial.println("🚀 SmartGate V5 PORTE prêt !");
  Serial.println("============================");

  // Bip démarrage
  bipCourt(); delay(100); bipCourt();

  // LEDs éteintes — s'allume seulement vert/rouge à l'accès
  setLEDs(0, 0, 0);
}


// ══════════════════════════════════════════════
// LOOP
// ══════════════════════════════════════════════
void loop() {

  // ── RFID ────────────────────────────────────
  if (rfid.PICC_IsNewCardPresent() && rfid.PICC_ReadCardSerial()) {
    String uid = "";
    for (byte i = 0; i < rfid.uid.size; i++) {
      if (rfid.uid.uidByte[i] < 0x10) uid += "0";
      uid += String(rfid.uid.uidByte[i], HEX);
    }
    uid.toUpperCase();
    Serial.println("🪪 RFID: " + uid);
    handleResult(sendAccess("rfid", uid));
    delay(2000);
    setLEDs(0, 0, 0);  // Éteindre après 2s
  }

  // ── Empreinte ───────────────────────────────
  int fid = getFingerID();
  if (fid > 0) {
    Serial.println("🔏 Empreinte ID: " + String(fid));
    handleResult(sendAccess("fingerprint", String(fid)));
    delay(2000);
    setLEDs(0, 0, 0);  // Éteindre après 2s
    } else if(fid==-2){
      Serial.println("⚠️ Empreinte inconnue");
      // Envoyer avec timestamp unique pour forcer affichage terminal
      handleResult(sendAccess("fingerprint", "unknown_" + String(millis())));
      delay(1500);
      setLEDs(0, 0, 0);
    }

  // ── Sync toutes les 30s ─────────────────────
  if (millis() - lastSync > SYNC_INT) {
    lastSync = millis();
    syncFingerprints();
  }

  // ── Suppression RGPD toutes les 3s ─────────
  if (millis() - lastDelete > DELETE_INT) {
    lastDelete = millis();
    checkDelete();
  }
}


// ══════════════════════════════════════════════
// ENVOI ACCÈS API
// ══════════════════════════════════════════════
String sendAccess(String type, String value) {
  if (WiFi.status() != WL_CONNECTED) return "";
  HTTPClient http;
  http.begin(SERVER + "/api/access?type=" + type
             + "&id=" + value
             + "&key=" + API_KEY
             + "&terminal=GATE_1");
  int code = http.GET();
  String resp = (code > 0) ? http.getString() : "";
  http.end();
  return resp;
}


// ══════════════════════════════════════════════
// GESTION RÉSULTAT → LEDs + Buzzer
// ══════════════════════════════════════════════
void handleResult(String response){

  Serial.println("📨 " + response);

  if(response == "" || response.length() < 5){
    Serial.println("❌ ERREUR API");
    setLEDs(255,0,0);
    bipLong();
    return;
  }

  if(response.indexOf("AUTHORIZED")!=-1){
    Serial.println("✅ ACCÈS AUTORISÉ");
    setLEDs(0,255,0);
    bipCourt();
  }
  else{
    Serial.println("❌ ACCÈS REFUSÉ");
    setLEDs(255,0,0);
    bipLong();
  }
}


// ══════════════════════════════════════════════
// BUZZER KY-012 (actif → digitalWrite)
// ══════════════════════════════════════════════
void bipCourt(){
  tone(PIN_BUZZ, 2000); // fréquence
  delay(120);
  noTone(PIN_BUZZ);
}

void bipLong(){
  for(int i=0;i<3;i++){
    tone(PIN_BUZZ, 1000);
    delay(250);
    noTone(PIN_BUZZ);
    delay(120);
  }
}


// ══════════════════════════════════════════════
// LEDs WS2812B
// ══════════════════════════════════════════════
void setLEDs(uint8_t r, uint8_t g, uint8_t b) {
  for (int i = 0; i < NUM_LEDS; i++)
    strip.setPixelColor(i, strip.Color(r, g, b));
  strip.show();
}


// ══════════════════════════════════════════════
// RECONNAISSANCE EMPREINTE
// ══════════════════════════════════════════════
int getFingerID(){

  uint8_t p = finger.getImage();

  if(p == FINGERPRINT_NOFINGER) return -1;

  if(p != FINGERPRINT_OK){
    Serial.println("❌ erreur image");
    return -1;
  }

  p = finger.image2Tz();
  if(p != FINGERPRINT_OK){
    Serial.println("❌ erreur conversion");
    return -1;
  }

  p = finger.fingerSearch();

  if(p == FINGERPRINT_OK){
    Serial.println("✅ Trouvé ID=" + String(finger.fingerID));
    return finger.fingerID;
  }

  Serial.println("❌ Empreinte inconnue");
  return -2;
}


// ══════════════════════════════════════════════
// DÉCHIFFREMENT AES-256
// ══════════════════════════════════════════════
bool decryptModel(const char* b64, uint8_t* out, uint16_t* outLen) {
  uint16_t b64len = strlen(b64);
  uint16_t encLen = 0;
  const char* chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
  uint8_t buf[4];
  uint8_t bufIdx = 0;

  for (uint16_t i = 0; i < b64len && encLen < 696; i++) {
    char c = b64[i];
    if (c == '=') break;
    const char* pos = strchr(chars, c);
    if (!pos) continue;
    buf[bufIdx++] = pos - chars;
    if (bufIdx == 4) {
      encBuffer[encLen++] = (buf[0] << 2) | (buf[1] >> 4);
      encBuffer[encLen++] = (buf[1] << 4) | (buf[2] >> 2);
      encBuffer[encLen++] = (buf[2] << 6) | buf[3];
      bufIdx = 0;
    }
  }

  if (encLen == 0) {
    Serial.println("❌ Base64 decode failed");
    return false;
  }

  byte iv[16];
  memcpy(iv, AES_IV, 16);
  aesLib.decrypt(encBuffer, encLen, out, AES_KEY, 256, iv);
  *outLen = 512;
  return true;
}


// ══════════════════════════════════════════════
// TRACKING LOCAL DES SLOTS SYNCHRONISÉS
// ══════════════════════════════════════════════
static bool syncedSlots[128];
static bool syncInit = false;

// ══════════════════════════════════════════════
// INJECTION MODÈLE — SANS loadModel() !
// ══════════════════════════════════════════════
bool injectModel(uint8_t* data, uint16_t len, int slotId) {

  // Vider le buffer série
  while (mySerial.available()) mySerial.read();
  delay(50);

  // ── Envoyer commande DownChar (CMD=0x09) ──
  // CHK = 0x01+0x00+0x04+0x09+0x01 = 0x0F
  uint8_t cmd[] = {
    0xEF, 0x01,             // Header
    0xFF, 0xFF, 0xFF, 0xFF, // Adresse
    0x01,                   // PID = commande
    0x00, 0x04,             // Longueur = 4
    0x09,                   // CMD = DownChar
    0x01,                   // BufferID = 1
    0x00, 0x0F              // Checksum
  };
  mySerial.write(cmd, sizeof(cmd));
  delay(300);
  while (mySerial.available()) mySerial.read(); // lire ACK

  // ── Envoyer les données en paquets ──
  uint16_t packetSize = 128;
  uint16_t nbPackets  = (len + packetSize - 1) / packetSize;

  for (uint16_t p = 0; p < nbPackets; p++) {
    uint16_t offset = p * packetSize;
    uint16_t size   = min((uint16_t)packetSize, (uint16_t)(len - offset));
    bool     last   = (p == nbPackets - 1);

    // FIX CRITIQUE : PID=0x02 milieu, PID=0x08 dernier (pas 0x01/0x02 !)
    uint8_t  pid    = last ? 0x08 : 0x02;
    uint16_t pktLen = size + 2;

    mySerial.write(0xEF); mySerial.write(0x01);
    mySerial.write(0xFF); mySerial.write(0xFF);
    mySerial.write(0xFF); mySerial.write(0xFF);
    mySerial.write(pid);
    mySerial.write(pktLen >> 8);
    mySerial.write(pktLen & 0xFF);

    uint16_t sum = pid + (pktLen >> 8) + (pktLen & 0xFF);
    for (uint16_t i = 0; i < size; i++) {
      mySerial.write(data[offset + i]);
      sum += data[offset + i];
    }
    mySerial.write(sum >> 8);
    mySerial.write(sum & 0xFF);
    delay(50);
  }
  delay(500);

  // ── Stocker dans le capteur ──
  uint8_t result = finger.storeModel(slotId);
  if (result == FINGERPRINT_OK) {
    Serial.println("  ✅ Installé slot " + String(slotId));
    return true;
  }
  Serial.println("  ❌ storeModel failed slot " + String(slotId) + " err=" + String(result));
  return false;
}
// ══════════════════════════════════════════════
// SYNCHRONISATION — TRACKING LOCAL (pas loadModel)
// ══════════════════════════════════════════════
void syncFingerprints() {
  if (WiFi.status() != WL_CONNECTED) return;

  // Init tableau au premier démarrage
  if (!syncInit) {
    memset(syncedSlots, false, sizeof(syncedSlots));
    syncInit = true;
  }

  HTTPClient http;
  http.begin(SERVER + "/api/fingerprints_to_sync?key=" + API_KEY);
  if (http.GET() != 200) { http.end(); return; }

  DynamicJsonDocument doc(1024);
  DeserializationError err = deserializeJson(doc, http.getString());
  http.end();

  if (err || !doc["ok"]) return;

  int synced  = 0;
  int skipped = 0;

  for (JsonObject user : doc["users"].as<JsonArray>()) {
    int userId = user["id"];
    int fid    = user["fingerprint_id"];

    if (fid < 1 || fid > 127) continue;

    Serial.println("🔄 User=" + String(userId) + " FID=" + String(fid));

    // ── Déjà sync cette session → skip SANS loadModel ──
    if (syncedSlots[fid]) {
      Serial.println("  ⏭ Déjà sync — skip");
      skipped++;
      continue;
    }

    // ── Télécharger le modèle ──
    HTTPClient h2;
    h2.begin(SERVER + "/api/get_fingerprint_model?key=" + API_KEY
             + "&user_id=" + String(userId));

    if (h2.GET() != 200) {
      Serial.println("  ❌ Download failed");
      h2.end();
      continue;
    }

    DynamicJsonDocument d(2048);
    DeserializationError err2 = deserializeJson(d, h2.getString());
    h2.end();

    if (err2 || !d["ok"]) {
      Serial.println("  ❌ JSON invalide");
      continue;
    }

    uint16_t len = 0;
    if (!decryptModel(d["model"], modelBuffer, &len)) {
      Serial.println("  ❌ Decrypt failed");
      continue;
    }

    // ── Injecter SANS loadModel préalable ──
    if (injectModel(modelBuffer, len, fid)) {
      syncedSlots[fid] = true;
      synced++;
    }

    delay(300);
  }

  Serial.println("✅ Sync: " + String(synced) + " installé(s), "
                 + String(skipped) + " déjà présent(s)");
}

// ══════════════════════════════════════════════
// SUPPRESSION RGPD
// ══════════════════════════════════════════════
void checkDelete() {
  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  http.begin(SERVER + "/api/delete_fingerprint_request?key=" + API_KEY
             + "&terminal=GATE_1");
  if (http.GET() != 200) { http.end(); return; }

  String body = http.getString();
  http.end();

  if (body.indexOf("\"delete\":true") == -1) return;

  int fs = body.indexOf("\"fingerprint_id\":") + 17;
  int fe = body.indexOf(",", fs);
  if (fe == -1) fe = body.indexOf("}", fs);

  int qs = body.indexOf("\"queue_id\":") + 11;
  int qe = body.indexOf(",", qs);
  if (qe == -1) qe = body.indexOf("}", qs);

  int fid = body.substring(fs, fe).toInt();
  int qid = body.substring(qs, qe).toInt();

  Serial.println("🗑 RGPD: suppression ID=" + String(fid));
  finger.deleteModel(fid);

  HTTPClient h2;
  h2.begin(SERVER + "/api/delete_fingerprint_done?queue_id=" + String(qid));
  h2.GET();
  h2.end();
  Serial.println("✅ Supprimé (RGPD)");
}
