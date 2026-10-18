#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <PubSubClient.h>
#include <LiteBoardSetup.h>
#include "mqtt_ca_cert.h"
#include "device_config.h"

constexpr unsigned long TELEMETRY_INTERVAL_MS = 5000;
// Every Nth telemetry packet reports an over-temperature value (> 100 C) to exercise alert rules.
constexpr unsigned long OVER_TEMPERATURE_EVERY_N_PACKETS = 3;
constexpr int SCHEMA_VERSION = 1;
constexpr time_t MIN_VALID_EPOCH = 1700000000;
// Topic (~100 chars) + envelope payload (~150 chars) exceed PubSubClient's default 256-byte packet.
constexpr uint16_t MQTT_BUFFER_SIZE = 512;

WiFiClientSecure wifiClient;
PubSubClient mqttClient(wifiClient);

unsigned long lastPublishAt = 0;
unsigned long telemetryPacketCount = 0;
float simulatedTemperature = 25.0F;
char telemetryTopic[128];

void connectWiFi() {
  if (WiFi.status() == WL_CONNECTED) {
    return;
  }

  Serial.print("Connecting to Wi-Fi");
  LiteBoardSetup::connectWiFi(LB_WIFI_SSID, LB_WIFI_PASSWORD);

  Serial.println();
  Serial.print("Wi-Fi connected, IP: ");
  Serial.println(WiFi.localIP());
}

void syncTime() {
  Serial.print("Syncing time (NTP)");
  configTime(0, 0, "pool.ntp.org", "time.nist.gov");

  const unsigned long startedAt = millis();
  while (time(nullptr) < MIN_VALID_EPOCH && millis() - startedAt < 20000) {
    delay(500);
    Serial.print(".");
  }
  Serial.println(time(nullptr) >= MIN_VALID_EPOCH ? " done" : " timed out, will retry in background");
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

float overTemperature() {
  // 101.00 - 110.00 C, always above 100.
  return 100.0F + random(100, 1001) / 100.0F;
}

void makeMessageId(char *out, size_t size) {
  // Random UUID v4, unique per message, well under the 128 character limit.
  uint32_t r[4];
  for (uint32_t &part : r) {
    part = esp_random();
  }
  r[1] = (r[1] & 0xFFFF0FFF) | 0x00004000;
  r[2] = (r[2] & 0x3FFFFFFF) | 0x80000000;
  snprintf(out, size, "%08lx-%04lx-%04lx-%04lx-%04lx%08lx",
           static_cast<unsigned long>(r[0]),
           static_cast<unsigned long>(r[1] >> 16),
           static_cast<unsigned long>(r[1] & 0xFFFF),
           static_cast<unsigned long>(r[2] >> 16),
           static_cast<unsigned long>(r[2] & 0xFFFF),
           static_cast<unsigned long>(r[3]));
}

void publishTelemetry() {
  const time_t nowEpoch = time(nullptr);
  if (nowEpoch < MIN_VALID_EPOCH) {
    Serial.println("Clock not synced yet, skipping telemetry");
    return;
  }

  char messageId[40];
  makeMessageId(messageId, sizeof(messageId));

  char occurredAt[25];
  struct tm utc;
  gmtime_r(&nowEpoch, &utc);
  strftime(occurredAt, sizeof(occurredAt), "%Y-%m-%dT%H:%M:%SZ", &utc);

  telemetryPacketCount++;
  const bool overTemperatureSample = telemetryPacketCount % OVER_TEMPERATURE_EVERY_N_PACKETS == 0;
  const float temperature = overTemperatureSample ? overTemperature() : nextTemperature();
  char payload[256];
  const int length = snprintf(
      payload,
      sizeof(payload),
      "{\"message_id\":\"%s\",\"schema_version\":%d,\"occurred_at\":\"%s\","
      "\"values\":{\"temperature\":%.2f}}",
      messageId,
      SCHEMA_VERSION,
      occurredAt,
      temperature
  );
  if (length < 0 || length >= static_cast<int>(sizeof(payload))) {
    Serial.println("Telemetry payload too large, skipping");
    return;
  }

  const bool published = mqttClient.publish(telemetryTopic, payload);
  if (published) {
    Serial.print("Telemetry sent to ");
    Serial.print(telemetryTopic);
    Serial.print(": ");
    Serial.print(payload);
    Serial.println(overTemperatureSample ? " (over temperature)" : "");
  } else {
    Serial.println("Telemetry publish failed");
  }
}

void setup() {
  Serial.begin(115200);
  delay(500);
  randomSeed(esp_random());

  snprintf(telemetryTopic, sizeof(telemetryTopic), "%s", LB_TELEMETRY_TOPIC);

  connectWiFi();
  syncTime();
  LiteBoardSetup::configureMqttTls(
      wifiClient, mqttClient, LB_MQTT_CA_CERT, LB_HOST, LB_PORT);
  mqttClient.setBufferSize(MQTT_BUFFER_SIZE);
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
