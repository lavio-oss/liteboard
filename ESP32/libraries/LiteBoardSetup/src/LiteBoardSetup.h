#pragma once

#include <PubSubClient.h>
#include <WiFiClientSecure.h>
#include <stdint.h>

namespace LiteBoardSetup {

void connectWiFi(const char *ssid, const char *password);

void configureMqttTls(
    WiFiClientSecure &tlsClient,
    PubSubClient &mqttClient,
    const char *caCertificate,
    const char *host,
    uint16_t port,
    uint16_t keepAliveSeconds = 60
);

void configureMqtt(
    PubSubClient &mqttClient,
    const char *host,
    uint16_t port,
    uint16_t keepAliveSeconds = 60
);

}
