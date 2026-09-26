#include <WiFi.h>
#include <PubSubClient.h>

// Dien thong tin Wi-Fi va Access Token cua device ThingsBoard o day.
const char *WIFI_SSID = "Khanh Hoa";
const char *WIFI_PASSWORD = "phukhanh";
const char *TB_ACCESS_TOKEN = "0bszem6ntw2bi0d28q1a";

const char *TB_HOST = "mqtt.thingsboard.cloud";
constexpr uint16_t TB_PORT = 1883;
constexpr unsigned long TELEMETRY_INTERVAL_MS = 5000;

WiFiClient wifiClient;
PubSubClient mqttClient(wifiClient);

unsigned long lastPublishAt = 0;
float simulatedTemperature = 25.0F;

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

void connectThingsBoard() {
  while (!mqttClient.connected()) {
    const uint64_t chipId = ESP.getEfuseMac();
    char clientId[32];
    snprintf(clientId, sizeof(clientId), "ESP32-%llX",
             static_cast<unsigned long long>(chipId));

    Serial.print("Connecting to ThingsBoard MQTT... ");
    // Access Token la username; password de trong.
    if (mqttClient.connect(clientId, TB_ACCESS_TOKEN, nullptr)) {
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
  char payload[64];
  snprintf(payload, sizeof(payload), "{\"temperature\":%.2f}", temperature);

  const bool published = mqttClient.publish("v1/devices/me/telemetry", payload);
  if (published) {
    Serial.print("Telemetry sent: ");
    Serial.println(payload);
  } else {
    Serial.println("Telemetry publish failed");
  }
}

void setup() {
  Serial.begin(115200);
  delay(500);
  randomSeed(esp_random());

  connectWiFi();
  mqttClient.setServer(TB_HOST, TB_PORT);
  mqttClient.setKeepAlive(60);
  connectThingsBoard();
}

void loop() {
  if (WiFi.status() != WL_CONNECTED) {
    connectWiFi();
  }
  if (!mqttClient.connected()) {
    connectThingsBoard();
  }

  mqttClient.loop();

  const unsigned long now = millis();
  if (now - lastPublishAt >= TELEMETRY_INTERVAL_MS) {
    lastPublishAt = now;
    publishTelemetry();
  }
}
