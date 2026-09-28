#include <WiFi.h>
#include <PubSubClient.h>

// Dien thong tin Wi-Fi va cau hinh thiet bi LiteBoard o day.
const char *WIFI_SSID = "Khanh Hoa";
const char *WIFI_PASSWORD = "phukhanh";

const char *LB_HOST = "192.168.1.100";
constexpr uint16_t LB_PORT = 1883;
const char *LB_PROJECT_ID = "019c0000-0000-7000-8000-000000000002";
const char *LB_DEVICE_ID = "019c0000-0000-7000-8000-000000000100";
const char *LB_ACCESS_TOKEN = "0bszem6ntw2bi0d28q1a";

constexpr unsigned long TELEMETRY_INTERVAL_MS = 5000;

WiFiClient wifiClient;
PubSubClient mqttClient(wifiClient);

unsigned long lastPublishAt = 0;
float simulatedTemperature = 25.0F;
char telemetryTopic[128];

void connectWiFi() {
  if (WiFi.status() == WL_CONNECTED) {
    return;
  }

  Serial.print("Connecting to Wi-Fi");
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print('.');
  }

  Serial.println();
  Serial.print("Wi-Fi connected, IP: ");
  Serial.println(WiFi.localIP());
}

void connectLiteBoard() {
  while (!mqttClient.connected()) {
    const uint64_t chipId = ESP.getEfuseMac();
    char clientId[32];
    snprintf(clientId, sizeof(clientId), "ESP32-%llX",
             static_cast<unsigned long long>(chipId));

    Serial.print("Connecting to LiteBoard MQTT... ");
    // LiteBoard Access Token mode: username = LB_ACCESS_TOKEN, password = LB_ACCESS_TOKEN.
    if (mqttClient.connect(clientId, LB_ACCESS_TOKEN, LB_ACCESS_TOKEN)) {
      Serial.println("connected");
    } else {
      Serial.print("failed, state=");
      Serial.print(mqttClient.state());
      Serial.println("; retry in 3 seconds");
      delay(3000);
    }
  }
}

float nextTemperature() {
  // Random walk nho de nhiet do thay doi tu nhien quanh 25 do C.
  simulatedTemperature += random(-30, 31) / 100.0F;
  if (simulatedTemperature < 20.0F) {
    simulatedTemperature = 20.0F;
  } else if (simulatedTemperature > 30.0F) {
    simulatedTemperature = 30.0F;
  }
  return simulatedTemperature;
}

void publishTelemetry() {
  const float temperature = nextTemperature();
  char payload[128];
  snprintf(
      payload,
      sizeof(payload),
      "{\"values\":{\"temperature\":%.2f}}",
      temperature
  );

  const bool published = mqttClient.publish(telemetryTopic, payload);
  if (published) {
    Serial.print("Telemetry sent to ");
    Serial.print(telemetryTopic);
    Serial.print(": ");
    Serial.println(payload);
  } else {
    Serial.println("Telemetry publish failed");
  }
}

void setup() {
  Serial.begin(115200);
  delay(500);
  randomSeed(esp_random());

  snprintf(
      telemetryTopic,
      sizeof(telemetryTopic),
      "liteboard/%s/devices/%s/telemetry",
      LB_PROJECT_ID,
      LB_DEVICE_ID
  );

  connectWiFi();
  mqttClient.setServer(LB_HOST, LB_PORT);
  mqttClient.setKeepAlive(60);
  connectLiteBoard();
}

void loop() {
  if (WiFi.status() != WL_CONNECTED) {
    connectWiFi();
  }
  if (!mqttClient.connected()) {
    connectLiteBoard();
  }

  mqttClient.loop();

  const unsigned long now = millis();
  if (now - lastPublishAt >= TELEMETRY_INTERVAL_MS) {
    lastPublishAt = now;
    publishTelemetry();
  }
}
