#include <ESP8266WiFi.h>
#include <ESP8266WebServer.h>
#include <EEPROM.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClient.h>
#include <Keypad.h>
#include <time.h>

// ===================== IO =====================
// Relay -> D8 (GPIO15)  (ต้องเป็น Active HIGH เพื่อไม่ให้ติดตอนบูต)
static const int RELAY_PIN = D8;
static const bool RELAY_ACTIVE_LOW = false;
static const unsigned long RELAY_ACTION_MS = 3500;

// Buzzer -> RX (GPIO3)
static const int BUZZER_PIN = 3;
static const bool BUZZER_ACTIVE_HIGH = false;
static const unsigned long BEEP_ON_MS  = 90;
static const unsigned long BEEP_GAP_MS = 90;

// ===================== KEYPAD (ตามที่คุณกำหนด) =====================
const byte ROWS = 4;
const byte COLS = 4;

char keys[ROWS][COLS] = {
  {'1', '2', '3', 'A'},
  {'4', '5', '6', 'B'},
  {'7', '8', '9', 'C'},
  {'*', '0', '#', 'D'}
};

byte rowPin[ROWS] = {D0, D1, D2, D3};
byte colPin[COLS] = {D4, D5, D6, D7};

Keypad keypad = Keypad(makeKeymap(keys), rowPin, colPin, ROWS, COLS);

// ===================== CONFIG STORAGE =====================
ESP8266WebServer server(80);

struct Config {
  char ssid[32];
  char pass[64];
  char doorName[32];
  char doorId[48];
  char apiUrl[160];
  char doorsToken[64];
};
Config cfg;

static const int EEPROM_SIZE = 512;
static const int CFG_MAGIC_ADDR = 0;
static const int CFG_DATA_ADDR  = 4;
static const uint32_t CFG_MAGIC = 0xC0FFEE66;

const char* AP_SSID = "ESP8266-CONFIG";
const char* AP_PASS = "12345678";

// ===================== NTP =====================
static const char* NTP1 = "pool.ntp.org";
static const char* NTP2 = "time.google.com";
static const char* TZ_INFO = "ICT-7";
static const unsigned long NTP_RETRY_MS = 10000;
unsigned long lastNtpTry = 0;
bool timeReady = false;

// ===================== STATES =====================
bool relayOn = false;
unsigned long relayOffAt = 0;

String currentCode = "";
static const int MAX_CODE_LEN = 12;

static const unsigned long SAME_CODE_COOLDOWN_MS = 4000;
String lastCode = "";
unsigned long lastCodeAt = 0;

// ===================== HELPERS =====================
String htmlEscape(String s) {
  s.replace("&", "&amp;");
  s.replace("<", "&lt;");
  s.replace(">", "&gt;");
  s.replace("\"", "&quot;");
  s.replace("'", "&#39;");
  return s;
}

void setDefaultConfig() {
  memset(&cfg, 0, sizeof(cfg));
  strlcpy(cfg.apiUrl, "http://example.com/api/access_check", sizeof(cfg.apiUrl));
}

void loadConfig() {
  EEPROM.begin(EEPROM_SIZE);
  uint32_t magic = 0;
  EEPROM.get(CFG_MAGIC_ADDR, magic);
  if (magic != CFG_MAGIC) { setDefaultConfig(); return; }
  EEPROM.get(CFG_DATA_ADDR, cfg);
}

void saveConfig() {
  EEPROM.begin(EEPROM_SIZE);
  EEPROM.put(CFG_MAGIC_ADDR, CFG_MAGIC);
  EEPROM.put(CFG_DATA_ADDR, cfg);
  EEPROM.commit();
}

void clearConfig() {
  EEPROM.begin(EEPROM_SIZE);
  for (int i = 0; i < EEPROM_SIZE; i++) EEPROM.write(i, 0);
  EEPROM.commit();
  setDefaultConfig();
}

bool hasMinimumConfig() {
  if (strlen(cfg.ssid) == 0) return false;
  if (strlen(cfg.doorId) == 0) return false;
  if (strlen(cfg.apiUrl) == 0) return false;
  if (strlen(cfg.doorsToken) == 0) return false;
  return true;
}

// ===================== BUZZER =====================
void buzzerWrite(bool on) {
  if (BUZZER_ACTIVE_HIGH) digitalWrite(BUZZER_PIN, on ? HIGH : LOW);
  else                    digitalWrite(BUZZER_PIN, on ? LOW : HIGH);
}

void beepOnce() {
  buzzerWrite(true);
  delay(BEEP_ON_MS);
  buzzerWrite(false);
}

void beepOk() { beepOnce(); }

void beepFail() {
  beepOnce();
  delay(BEEP_GAP_MS);
  beepOnce();
}

// ===================== RELAY =====================
void relayWrite(bool on) {
  if (RELAY_ACTIVE_LOW) digitalWrite(RELAY_PIN, on ? LOW : HIGH);
  else                  digitalWrite(RELAY_PIN, on ? HIGH : LOW);
}

void setRelayAction(bool on) {
  relayOn = on;
  relayWrite(on);
  Serial.println(on ? "[RELAY] ON" : "[RELAY] OFF");
}

void triggerRelayAction(unsigned long ms = RELAY_ACTION_MS) {
  setRelayAction(true);
  relayOffAt = millis() + ms;
}

void handleRelayAutoOff() {
  if (relayOn && (long)(millis() - relayOffAt) >= 0) setRelayAction(false);
}

// ===================== WIFI =====================
bool connectWiFi(unsigned long timeoutMs = 12000) {
  if (strlen(cfg.ssid) == 0) return false;
  WiFi.mode(WIFI_STA);
  WiFi.begin(cfg.ssid, cfg.pass);

  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED) {
    delay(250);
    if (millis() - start > timeoutMs) return false;
  }
  return true;
}

void startAP() {
  WiFi.mode(WIFI_AP);
  WiFi.softAP(AP_SSID, AP_PASS);
  delay(200);
  Serial.println("\n=== AP MODE ===");
  Serial.print("AP SSID: "); Serial.println(AP_SSID);
  Serial.print("AP IP  : "); Serial.println(WiFi.softAPIP());
}

// ===================== NTP =====================
void beginNtp() {
  setenv("TZ", TZ_INFO, 1);
  tzset();
  configTime(0, 0, NTP1, NTP2);
  lastNtpTry = millis();
}

bool checkTimeReady() {
  time_t now = time(nullptr);
  return (now > 1609459200); // > 2021-01-01
}

void ensureTimeReady() {
  if (WiFi.status() != WL_CONNECTED) { timeReady = false; return; }
  if (timeReady) return;

  if (checkTimeReady()) {
    timeReady = true;
    Serial.print("[TIME] synced epoch="); Serial.println((long)time(nullptr));
    return;
  }
  if (millis() - lastNtpTry >= NTP_RETRY_MS) {
    Serial.println("[TIME] syncing via NTP...");
    beginNtp();
  }
}

long getEpochSeconds() { return (long)time(nullptr); }

// ===================== WEB UI =====================
String pageHeader(const String &title) {
  String css =
    "body{font-family:Arial,Helvetica,sans-serif;margin:0;background:#f5f6f8;}"
    ".wrap{max-width:820px;margin:0 auto;padding:16px;}"
    ".card{background:#fff;border-radius:14px;padding:16px;box-shadow:0 6px 20px rgba(0,0,0,.06);}"
    "h2{margin:0 0 12px 0;}"
    "label{display:block;margin-top:12px;font-weight:600;}"
    "input{width:100%;padding:10px;border:1px solid #ddd;border-radius:10px;font-size:16px;}"
    ".row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}"
    "@media(max-width:640px){.row{grid-template-columns:1fr;}}"
    "button,a.btn{display:inline-block;margin-top:14px;padding:10px 14px;border:0;border-radius:10px;"
    "background:#111;color:#fff;font-size:16px;text-decoration:none;cursor:pointer;}"
    ".muted{color:#666;font-size:13px;margin-top:8px;line-height:1.5;}"
    ".nav{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;}"
    ".danger{background:#b00020;}"
    "code{background:#f0f1f3;padding:2px 6px;border-radius:6px;}";
  return "<!doctype html><html><head><meta charset='utf-8'>"
         "<meta name='viewport' content='width=device-width,initial-scale=1'>"
         "<title>" + htmlEscape(title) + "</title>"
         "<style>" + css + "</style>"
         "</head><body><div class='wrap'>";
}
String pageFooter() { return "</div></body></html>"; }

void handleConfigPage() {
  String html = pageHeader("ESP8266 Door Config") +
    "<div class='card'>"
    "<h2>Door Access - Keypad Configuration</h2>"
    "<div class='muted'>ตั้งค่า: Wi-Fi / Door ID / doors_token / API URL</div>"
    "<form method='POST' action='/save'>"
    "<label>Wi-Fi SSID</label>"
    "<input name='ssid' value='" + htmlEscape(String(cfg.ssid)) + "' required>"
    "<label>Wi-Fi Password</label>"
    "<input name='pass' type='password' placeholder='ถ้าไม่เปลี่ยนให้เว้นว่าง'>"
    "<div class='row'>"
      "<div><label>Door Name (optional)</label>"
      "<input name='door_name' value='" + htmlEscape(String(cfg.doorName)) + "'></div>"
      "<div><label>Door ID</label>"
      "<input name='door_id' value='" + htmlEscape(String(cfg.doorId)) + "' required></div>"
    "</div>"
    "<label>doors_token</label>"
    "<input name='doors_token' value='" + htmlEscape(String(cfg.doorsToken)) + "' required>"
    "<label>API URL (POST)</label>"
    "<input name='api' value='" + htmlEscape(String(cfg.apiUrl)) + "' required>"
    "<button type='submit'>Save & Restart</button>"
    "</form>"
    "<div class='nav'>"
      "<a class='btn' href='/status'>Status</a>"
      "<a class='btn danger' href='/reset' onclick=\"return confirm('Reset config?');\">Reset</a>"
    "</div>"
    "</div>" + pageFooter();
  server.send(200, "text/html", html);
}

void handleSave() {
  String ssid = server.arg("ssid"); ssid.trim();
  String pass = server.arg("pass"); pass.trim();
  String doorName = server.arg("door_name"); doorName.trim();
  String doorId = server.arg("door_id"); doorId.trim();
  String doorsToken = server.arg("doors_token"); doorsToken.trim();
  String api = server.arg("api"); api.trim();

  strlcpy(cfg.ssid, ssid.c_str(), sizeof(cfg.ssid));
  if (pass.length() > 0) strlcpy(cfg.pass, pass.c_str(), sizeof(cfg.pass));
  strlcpy(cfg.doorName, doorName.c_str(), sizeof(cfg.doorName));
  strlcpy(cfg.doorId, doorId.c_str(), sizeof(cfg.doorId));
  strlcpy(cfg.doorsToken, doorsToken.c_str(), sizeof(cfg.doorsToken));
  strlcpy(cfg.apiUrl, api.c_str(), sizeof(cfg.apiUrl));

  saveConfig();

  server.send(200, "text/html",
    pageHeader("Saved") +
    "<div class='card'><h2>Saved!</h2><div class='muted'>บันทึกแล้ว กำลังรีสตาร์ท...</div></div>" +
    pageFooter()
  );
  delay(1200);
  ESP.restart();
}

void handleStatus() {
  String mode = (WiFi.getMode() == WIFI_AP) ? "AP" : "STA";
  String ip = (WiFi.getMode() == WIFI_AP) ? WiFi.softAPIP().toString() : WiFi.localIP().toString();
  String wifiStatus = (WiFi.status() == WL_CONNECTED) ? "CONNECTED" : "NOT CONNECTED";
  String epochNow = timeReady ? String(getEpochSeconds()) : "-";

  String html = pageHeader("Status") +
    "<div class='card'>"
    "<h2>Status</h2>"
    "<p><b>Mode:</b> " + htmlEscape(mode) + "</p>"
    "<p><b>IP:</b> " + htmlEscape(ip) + "</p>"
    "<p><b>Wi-Fi:</b> " + htmlEscape(wifiStatus) + "</p>"
    "<p><b>Time Synced:</b> " + String(timeReady ? "YES" : "NO") + "</p>"
    "<p><b>Epoch Now:</b> <code>" + htmlEscape(epochNow) + "</code></p>"
    "<hr>"
    "<p><b>Door Name:</b> " + htmlEscape(String(cfg.doorName)) + "</p>"
    "<p><b>Door ID:</b> " + htmlEscape(String(cfg.doorId)) + "</p>"
    "<p><b>doors_token:</b> <code>" + htmlEscape(String(cfg.doorsToken)) + "</code></p>"
    "<p><b>API URL:</b> " + htmlEscape(String(cfg.apiUrl)) + "</p>"
    "<p><b>Relay State:</b> " + String(relayOn ? "ON" : "OFF") + "</p>"
    "<p><b>Code Buffer:</b> <code>" + htmlEscape(currentCode) + "</code></p>"
    "<div class='muted'>Pins: Relay=D8(GPIO15), Buzzer=RX(GPIO3), Keypad uses D0-D7</div>"
    "<div class='nav'><a class='btn' href='/'>Config</a>"
    "<a class='btn danger' href='/reset' onclick=\"return confirm('Reset config?');\">Reset</a></div>"
    "</div>" + pageFooter();

  server.send(200, "text/html", html);
}

void handleReset() {
  clearConfig();
  saveConfig();
  server.send(200, "text/html",
    pageHeader("Reset") +
    "<div class='card'><h2>Reset Done</h2><div class='muted'>ล้างค่าแล้ว กำลังรีสตาร์ท...</div></div>" +
    pageFooter()
  );
  delay(1200);
  ESP.restart();
}

void handleNotFound() { server.send(404, "text/plain", "404 Not Found"); }

void startWebServer() {
  server.on("/", HTTP_GET, handleConfigPage);
  server.on("/save", HTTP_POST, handleSave);
  server.on("/status", HTTP_GET, handleStatus);
  server.on("/reset", HTTP_GET, handleReset);
  server.onNotFound(handleNotFound);
  server.begin();
  Serial.println("[WEB] server started on port 80");
}

// ===================== API =====================
bool parseAllowedFromJson(const String &body) {
  String s = body; s.toLowerCase();
  int idx = s.indexOf("\"allowed\"");
  if (idx < 0) idx = s.indexOf("allowed");
  if (idx < 0) return false;

  int t = s.indexOf("true", idx);
  int f = s.indexOf("false", idx);
  if (t >= 0 && (f < 0 || t < f)) return true;

  int colon = s.indexOf(':', idx);
  if (colon >= 0) {
    int p = colon + 1;
    while (p < (int)s.length() && isspace(s[p])) p++;
    if (p < (int)s.length() && s[p] == '1') return true;
  }
  return false;
}

bool shouldSendNow(const String &code) {
  if (relayOn) return false;
  if (code.length() == 0) return false;
  if (code == lastCode && (millis() - lastCodeAt) < SAME_CODE_COOLDOWN_MS) return false;
  return true;
}

bool sendAccessCheck(const String &code, long ts, String &respBodyOut, int &httpCodeOut) {
  WiFiClient client;
  HTTPClient http;

  if (!http.begin(client, String(cfg.apiUrl))) {
    httpCodeOut = -1;
    respBodyOut = "";
    return false;
  }

  http.addHeader("Content-Type", "application/json");

  String payload =
    "{"
      "\"door_id\":\"" + String(cfg.doorId) + "\","
      "\"card_uid\":\"" + code + "\","
      "\"passcode\":\"" + code + "\","
      "\"ts\":" + String(ts) + ","
      "\"doors_token\":\"" + String(cfg.doorsToken) + "\""
    "}";

  int codeHttp = http.POST(payload);
  String body = (codeHttp > 0) ? http.getString() : "";
  http.end();

  httpCodeOut = codeHttp;
  respBodyOut = body;
  return (codeHttp > 0);
}

void processAccess(const String &code) {
  if (WiFi.status() != WL_CONNECTED || !hasMinimumConfig()) { Serial.println("[API] skip (wifi/config not ready)"); beepFail(); return; }
  if (!timeReady) { Serial.println("[API] skip (time not synced)"); beepFail(); return; }

  long ts = getEpochSeconds();
  String body;
  int httpCode = 0;

  sendAccessCheck(code, ts, body, httpCode);

  Serial.print("[API] code="); Serial.print(httpCode);
  Serial.print(" code="); Serial.print(code);
  Serial.print(" ts="); Serial.print(ts);
  Serial.print(" body="); Serial.println(body);

  if (httpCode == 200) {
    bool allowed = parseAllowedFromJson(body);
    if (allowed) { beepOk(); triggerRelayAction(RELAY_ACTION_MS); }
    else { beepFail(); }
  } else {
    beepFail();
  }
}

// ===================== SETUP / LOOP =====================
void setup() {
  Serial.begin(115200);
  delay(200);

  // Relay init (สำคัญ: ทำให้ D8 LOW ตั้งแต่ต้น เพื่อบูตชัวร์)
  pinMode(RELAY_PIN, OUTPUT);
  relayWrite(false);
  relayOn = false;

  // Buzzer init
  pinMode(BUZZER_PIN, OUTPUT);
  buzzerWrite(false);

  setDefaultConfig();
  loadConfig();

  Serial.println("\n=== BOOT ESP8266 DOOR (KEYPAD) ===");

  bool ok = connectWiFi();
  if (ok) {
    Serial.println("[WIFI] connected");
    Serial.print("[WIFI] IP: "); Serial.println(WiFi.localIP());
    Serial.println("[TIME] init NTP...");
    beginNtp();
  } else {
    Serial.println("[WIFI] connect failed -> start AP portal");
    startAP();
  }

  startWebServer();
}

void loop() {
  server.handleClient();
  handleRelayAutoOff();
  ensureTimeReady();

  char key = keypad.getKey();
  if (!key) return;

  if (key == '*') {
    currentCode = "";
    Serial.println("[KEYPAD] clear");
    beepOnce();
    return;
  }

  if (key == '#') {
    Serial.print("[KEYPAD] submit code="); Serial.println(currentCode);

    if (!shouldSendNow(currentCode)) {
      Serial.println("[KEYPAD] blocked (relay/cooldown/empty)");
      beepFail();
      currentCode = "";
      return;
    }

    processAccess(currentCode);

    lastCode = currentCode;
    lastCodeAt = millis();
    currentCode = "";
    return;
  }

  if ((int)currentCode.length() < MAX_CODE_LEN) {
    currentCode += key;
    Serial.print("[KEYPAD] key="); Serial.print(key);
    Serial.print(" buffer="); Serial.println(currentCode);
  } else {
    Serial.println("[KEYPAD] buffer full");
    beepFail();
  }
}
