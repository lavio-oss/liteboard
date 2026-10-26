#include "LiteBoardSetup.h"

#include <Arduino.h>
#include <WiFi.h>

namespace LiteBoardSetup {

void connectWiFi(const char *ssid, const char *password) {
    if (WiFi.status() == WL_CONNECTED) {
        Serial.print("[Wi-Fi] Already connected. IP: ");
        Serial.println(WiFi.localIP());
        return;
    }

    Serial.println("[Wi-Fi] Connecting...");
    WiFi.mode(WIFI_STA);
    WiFi.begin(ssid, password);

    unsigned long lastStatusLogAt = millis();
    while (WiFi.status() != WL_CONNECTED) {
        delay(500);
        if (millis() - lastStatusLogAt >= 5000) {
            Serial.printf("[Wi-Fi] Still waiting; status=%d\n", WiFi.status());
            lastStatusLogAt = millis();
        }
    }

    Serial.print("[Wi-Fi] Connected. IP: ");
    Serial.print(WiFi.localIP());
    Serial.print("; RSSI: ");
    Serial.print(WiFi.RSSI());
    Serial.println(" dBm");
}

void configureMqttTls(
    WiFiClientSecure &tlsClient,
    PubSubClient &mqttClient,
    const char *caCertificate,
    const char *host,
    uint16_t port,
    uint16_t keepAliveSeconds
) {
    tlsClient.setCACert(caCertificate);
    configureMqtt(mqttClient, host, port, keepAliveSeconds);
}

void configureMqtt(
    PubSubClient &mqttClient,
    const char *host,
    uint16_t port,
    uint16_t keepAliveSeconds
) {
    mqttClient.setServer(host, port);
    mqttClient.setKeepAlive(keepAliveSeconds);
}

}
