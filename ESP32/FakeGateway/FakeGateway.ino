#include <WiFi.h>
#include <WiFiClient.h>
#include <PubSubClient.h>
#include <LiteBoardSetup.h>
#include "device_config.h"

constexpr unsigned long TELEMETRY_INTERVAL_MS = 5000;
// Two simulated sub-devices; unknown codes become sub-devices on the gateway's first message.
const char *SUB_DEVICE_CODES[] = {"fake-sensor-1", "fake-sensor-2"};
constexpr size_t SUB_DEVICE_COUNT = sizeof(SUB_DEVICE_CODES) / sizeof(SUB_DEVICE_CODES[0]);
constexpr uint16_t MQTT_BUFFER_SIZE = 768;

WiFiClient wifiClient;
PubSubClient mqttClient(wifiClient);

unsigned long lastPublishAt = 0;
float simulatedTemperature[SUB_DEVICE_COUNT] = {24.0F, 27.0F};
char connectTopic[128];
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

void announceSubDevices() {
  for (const char *code : SUB_DEVICE_CODES) {
    char payload[96];
    snprintf(payload, sizeof(payload), "{\"device\":\"%s\"}", code);
    Serial.print("connect ");
    Serial.println(mqttClient.publish(connectTopic, payload) ? payload : "failed");
  }
}

void connectLiteBoard() {
  while (!mqttClient.connected()) {
    const uint64_t chipId = ESP.getEfuseMac();
    char clientId[40];
    snprintf(clientId, sizeof(clientId), "ESP32-GW-%llX", static_cast<unsigned long long>(chipId));

    Serial.print("Connecting to LiteBoard MQTT... ");
    // Access Token mode: username = password = the gateway's token.
    if (mqttClient.connect(clientId, LB_ACCESS_TOKEN, LB_ACCESS_TOKEN)) {
      Serial.println("connected");
      announceSubDevices();
    } else {
      Serial.print("failed, state=");
      Serial.print(mqttClient.state());
      Serial.println("; retry in 3 seconds");
      delay(3000);
    }
  }
}

void makeMessageId(char *out, size_t size) {
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
  // One message carries every sub-device: {"<code>":[{...}], ...}. Items use the flat schemaless form
  // (message_id plus metrics), which the project's Default profile accepts.
  char payload[MQTT_BUFFER_SIZE - 160];
  size_t used = snprintf(payload, sizeof(payload), "{");

  for (size_t index = 0; index < SUB_DEVICE_COUNT; index++) {
    simulatedTemperature[index] += random(-30, 31) / 100.0F;
    const float humidity = 40.0F + random(0, 2001) / 100.0F;

    char messageId[40];
    makeMessageId(messageId, sizeof(messageId));

    const int written = snprintf(
        payload + used,
        sizeof(payload) - used,
        "%s\"%s\":[{\"message_id\":\"%s\",\"temperature\":%.2f,\"humidity\":%.2f}]",
        index == 0 ? "" : ",",
        SUB_DEVICE_CODES[index],
        messageId,
        simulatedTemperature[index],
        humidity
    );
    if (written < 0 || used + written >= sizeof(payload) - 2) {
      Serial.println("Gateway payload too large, skipping");
      return;
    }
    used += written;
  }
  snprintf(payload + used, sizeof(payload) - used, "}");

  Serial.print(mqttClient.publish(telemetryTopic, payload) ? "Telemetry sent: " : "Telemetry publish failed: ");
  Serial.println(payload);
}

void setup() {
  Serial.begin(115200);
  delay(500);
  randomSeed(esp_random());

  snprintf(connectTopic, sizeof(connectTopic), "liteboard/%s/devices/%s/gateway/connect", LB_PROJECT_ID, LB_GATEWAY_ID);
  snprintf(telemetryTopic, sizeof(telemetryTopic), "liteboard/%s/devices/%s/gateway/telemetry", LB_PROJECT_ID, LB_GATEWAY_ID);

  connectWiFi();
  LiteBoardSetup::configureMqtt(mqttClient, LB_HOST, LB_PORT);
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
