#include <HTTPClient.h>
#include <Preferences.h>
#include <PubSubClient.h>
#include <WiFi.h>
#include <WiFiClient.h>
#include <WiFiClientSecure.h>
#include <cJSON.h>
#include <cstring>
#include <time.h>
#include <LiteBoardSetup.h>

#include "provisioning_ca_cert.h"
#include "provisioning_config.h"
#include "../AccessToken/mqtt_ca_cert.h"

Preferences preferences;
WiFiClient httpClient;
WiFiClientSecure httpsClient;
WiFiClientSecure mqttTlsClient;
PubSubClient mqttClient(mqttTlsClient);
String telemetryTopic;
unsigned long lastPublishAt = 0;
float simulatedTemperature = 25.0F;
bool mqttTlsRejected = false;
bool approvalRejected = false;
String approvalStatus = "";
String provisioningRequestId = "";

bool waitForTime() {
  Serial.println("[NTP] Waiting for network time...");
  configTime(0, 0, "pool.ntp.org", "time.nist.gov");
  const unsigned long startedAt = millis();
  unsigned long lastStatusLogAt = startedAt;
  while (time(nullptr) < 1700000000 && millis() - startedAt < 60000) {
    delay(250);
    if (millis() - lastStatusLogAt >= 10000) {
      Serial.println("[NTP] Still waiting for network time...");
      lastStatusLogAt = millis();
    }
  }
  const bool synchronized = time(nullptr) >= 1700000000;
  Serial.println(synchronized ? "[NTP] Time synchronized." : "[NTP] Time sync timed out.");
  return synchronized;
}

String makeRequestId() {
  uint8_t bytes[16];
  for (size_t index = 0; index < sizeof(bytes); index += 4) {
    const uint32_t randomValue = esp_random();
    memcpy(bytes + index, &randomValue, 4);
  }
  bytes[6] = (bytes[6] & 0x0F) | 0x40;
  bytes[8] = (bytes[8] & 0x3F) | 0x80;
  char value[37];
  snprintf(value, sizeof(value),
           "%02x%02x%02x%02x-%02x%02x-%02x%02x-%02x%02x-%02x%02x%02x%02x%02x%02x",
           bytes[0], bytes[1], bytes[2], bytes[3], bytes[4], bytes[5], bytes[6],
           bytes[7], bytes[8], bytes[9], bytes[10], bytes[11], bytes[12],
           bytes[13], bytes[14], bytes[15]);
  return String(value);
}

String makeDeviceCode() {
  char value[32];
  snprintf(value, sizeof(value), "esp32-%llx",
           static_cast<unsigned long long>(ESP.getEfuseMac()));
  return String(value);
}

String makePendingPayload() {
  cJSON *payload = cJSON_CreateObject();
  cJSON_AddStringToObject(payload, "provision_key", LB_PROVISION_KEY);
  cJSON_AddStringToObject(payload, "provision_secret", LB_PROVISION_SECRET);
  cJSON_AddStringToObject(payload, "device_name", LB_DEVICE_NAME);
  cJSON_AddStringToObject(payload, "device_code", makeDeviceCode().c_str());
  cJSON_AddStringToObject(payload, "request_id", makeRequestId().c_str());
  cJSON_AddStringToObject(payload, "firmware_version", LB_FIRMWARE_VERSION);
  char *serialized = cJSON_PrintUnformatted(payload);
  String result = serialized == nullptr ? String() : String(serialized);
  cJSON_free(serialized);
  cJSON_Delete(payload);
  return result;
}

bool performBootstrap(String &enrollment) {
  String pending = preferences.getString("pending", "");
  if (pending.isEmpty()) {
    pending = makePendingPayload();
    if (pending.isEmpty() || preferences.putString("pending", pending) == 0) {
      Serial.println("Could not persist the pending enrollment record.");
      return false;
    }
  }

  const String provisioningUrl = LB_PROVISIONING_URL;
  const bool useHttps = provisioningUrl.startsWith("https://");
  if (!useHttps && !provisioningUrl.startsWith("http://")) {
    Serial.println("Provisioning URL must use http:// or https://.");
    return false;
  }
  if (useHttps) {
    httpsClient.setCACert(LB_PROVISIONING_CA_CERT);
  }

  HTTPClient http;
  http.setFollowRedirects(HTTPC_DISABLE_FOLLOW_REDIRECTS);
  http.setTimeout(15000);
  const char *responseHeaders[] = {"Retry-After"};
  http.collectHeaders(responseHeaders, 1);

  unsigned long retryDelayMs = 1000;
  while (true) {
    Serial.printf("[Bootstrap] Sending %s request...\n", useHttps ? "HTTPS" : "HTTP");
    const bool initialized = useHttps
                                 ? http.begin(httpsClient, LB_PROVISIONING_URL)
                                 : http.begin(httpClient, LB_PROVISIONING_URL);
    if (!initialized) {
      Serial.println("Could not initialize the provisioning connection.");
      return false;
    }
    http.addHeader("Content-Type", "application/json");
    http.addHeader("Accept", "application/json");
    const int status = http.POST(pending);
    Serial.printf("[Bootstrap] HTTP status: %d\n", status);
    const String response = status > 0 ? http.getString() : String();
    const String retryAfterHeader = http.header("Retry-After");
    http.end();

    if (status == 200 || status == 201) {
      cJSON *root = cJSON_Parse(response.c_str());
      cJSON *statusField = cJSON_GetObjectItemCaseSensitive(root, "status");
      const bool success = cJSON_IsString(statusField) &&
                           strcmp(statusField->valuestring, "success") == 0;
      cJSON_Delete(root);
      if (!success || preferences.putString("enrollment", response) == 0) {
        Serial.println("Bootstrap response was invalid or could not be stored.");
        return false;
      }
      preferences.remove("pending");
      enrollment = response;
      Serial.println("[Bootstrap] Enrollment saved.");
      return true;
    }

    if (status < 0 && useHttps) {
      char tlsError[128] = {0};
      const int tlsErrorCode = httpsClient.lastError(tlsError, sizeof(tlsError));
      if (tlsErrorCode != 0) {
        Serial.printf("HTTPS TLS transport failed (%d): %s\n", tlsErrorCode,
                      tlsError);
        return false;
      }
    }

    if (status < 0 || status == 429 || status >= 500) {
      if (status < 0 && !useHttps) {
        Serial.printf("HTTP transport failed with client error %d; retrying.\n",
                      status);
      }
      unsigned long waitMs = retryDelayMs;
      if (!retryAfterHeader.isEmpty()) {
        waitMs = retryAfterHeader.toInt() * 1000UL;
      }
      Serial.printf("[Bootstrap] Retrying in %lu ms.\n", min(waitMs, 60000UL));
      delay(min(waitMs, 60000UL));
      retryDelayMs = min(retryDelayMs * 2, 60000UL);
      continue;
    }

    Serial.printf("Bootstrap was rejected with HTTP status %d.\n", status);
    return false;
  }
}

bool persistApprovalState(const String &state, const String &reason) {
  String enrollment = preferences.getString("enrollment", "");
  cJSON *root = cJSON_Parse(enrollment.c_str());
  cJSON *provisioning = cJSON_GetObjectItemCaseSensitive(root, "provisioning");
  cJSON *statusField = cJSON_GetObjectItemCaseSensitive(provisioning, "approval_status");
  if (!cJSON_IsObject(provisioning) || !cJSON_IsString(statusField) ||
      cJSON_SetValuestring(statusField, state.c_str()) == nullptr) {
    cJSON_Delete(root);
    return false;
  }

  if (cJSON_GetObjectItemCaseSensitive(provisioning, "rejection_reason") != nullptr) {
    cJSON_DeleteItemFromObjectCaseSensitive(provisioning, "rejection_reason");
  }
  cJSON *reasonField = state == "rejected"
                           ? cJSON_AddStringToObject(provisioning, "rejection_reason", reason.c_str())
                           : cJSON_AddNullToObject(provisioning, "rejection_reason");
  if (reasonField == nullptr) {
    cJSON_Delete(root);
    return false;
  }

  char *serialized = cJSON_PrintUnformatted(root);
  const bool stored = serialized != nullptr &&
                      preferences.putString("enrollment", serialized) > 0;
  if (serialized != nullptr) {
    cJSON_free(serialized);
  }
  cJSON_Delete(root);

  if (stored) {
    approvalStatus = state;
  }
  return stored;
}

bool ensureEnrollmentApprovalState(String &enrollment) {
  cJSON *root = cJSON_Parse(enrollment.c_str());
  if (!cJSON_IsObject(root)) {
    cJSON_Delete(root);
    return false;
  }

  cJSON *provisioning = cJSON_GetObjectItemCaseSensitive(root, "provisioning");
  if (provisioning == nullptr) {
    provisioning = cJSON_AddObjectToObject(root, "provisioning");
    if (provisioning == nullptr ||
        cJSON_AddStringToObject(provisioning, "request_id", "") == nullptr ||
        cJSON_AddStringToObject(provisioning, "approval_status", "approved") == nullptr) {
      cJSON_Delete(root);
      return false;
    }

    char *serialized = cJSON_PrintUnformatted(root);
    const bool stored = serialized != nullptr &&
                        preferences.putString("enrollment", serialized) > 0;
    if (serialized != nullptr) {
      enrollment = serialized;
      cJSON_free(serialized);
    }
    cJSON_Delete(root);
    return stored;
  }

  cJSON *statusField = cJSON_GetObjectItemCaseSensitive(provisioning, "approval_status");
  cJSON *requestIdField = cJSON_GetObjectItemCaseSensitive(provisioning, "request_id");
  const bool validStatus = cJSON_IsString(statusField) &&
                           (strcmp(statusField->valuestring, "pending") == 0 ||
                            strcmp(statusField->valuestring, "approved") == 0 ||
                            strcmp(statusField->valuestring, "rejected") == 0);
  const bool validRequestId = validStatus &&
                              (strcmp(statusField->valuestring, "pending") != 0 ||
                               cJSON_IsString(requestIdField));
  if (validRequestId) {
    approvalStatus = statusField->valuestring;
    provisioningRequestId = cJSON_IsString(requestIdField)
                               ? requestIdField->valuestring
                               : "";
  }
  cJSON_Delete(root);
  return validRequestId;
}

bool loadEnrollment(String &enrollment) {
  enrollment = preferences.getString("enrollment", "");
  if (!enrollment.isEmpty()) {
    if (!ensureEnrollmentApprovalState(enrollment)) {
      Serial.println("Saved enrollment approval state is invalid.");
      return false;
    }
    Serial.println("[Bootstrap] Saved enrollment found; skipping bootstrap.");
    return true;
  }
  Serial.println("[Bootstrap] No saved enrollment; starting bootstrap.");
  return performBootstrap(enrollment);
}

bool configureMqtt(const String &enrollment, String &accessToken) {
  cJSON *root = cJSON_Parse(enrollment.c_str());
  cJSON *credential = cJSON_GetObjectItemCaseSensitive(
      cJSON_GetObjectItemCaseSensitive(root, "credentials"), "access_token");
  cJSON *mqtt = cJSON_GetObjectItemCaseSensitive(root, "mqtt");
  cJSON *provisioning = cJSON_GetObjectItemCaseSensitive(root, "provisioning");
  cJSON *approval = cJSON_GetObjectItemCaseSensitive(provisioning, "approval_status");
  cJSON *requestId = cJSON_GetObjectItemCaseSensitive(provisioning, "request_id");
  cJSON *host = cJSON_GetObjectItemCaseSensitive(mqtt, "host");
  cJSON *port = cJSON_GetObjectItemCaseSensitive(mqtt, "port");
  cJSON *topics = cJSON_GetObjectItemCaseSensitive(mqtt, "topics");
  cJSON *telemetry = cJSON_GetObjectItemCaseSensitive(topics, "telemetry");
  const bool valid = cJSON_IsString(credential) && cJSON_IsString(host) &&
                     cJSON_IsNumber(port) && cJSON_IsString(telemetry) &&
                     cJSON_IsString(approval) &&
                     (strcmp(approval->valuestring, "pending") == 0 ||
                      strcmp(approval->valuestring, "approved") == 0 ||
                      strcmp(approval->valuestring, "rejected") == 0) &&
                     (strcmp(approval->valuestring, "pending") != 0 ||
                      cJSON_IsString(requestId));
  if (valid) {
    accessToken = credential->valuestring;
    approvalStatus = approval->valuestring;
    provisioningRequestId = cJSON_IsString(requestId) ? requestId->valuestring : "";
    telemetryTopic = telemetry->valuestring;
    const char *mqttHost = LB_MQTT_HOST_OVERRIDE[0] == '\0'
                               ? host->valuestring
                               : LB_MQTT_HOST_OVERRIDE;
    LiteBoardSetup::configureMqttTls(
        mqttTlsClient,
        mqttClient,
        LB_MQTT_CA_CERT,
        mqttHost,
        static_cast<uint16_t>(port->valueint)
    );
  }
  cJSON_Delete(root);
  return valid;
}

String approvalStatusUrl(const String &requestId) {
  String url = LB_PROVISIONING_URL;
  const int bootstrapIndex = url.lastIndexOf("/bootstrap");
  if (bootstrapIndex < 0 || bootstrapIndex + 10 != static_cast<int>(url.length())) {
    return "";
  }

  url.remove(bootstrapIndex);
  url += "/status?request_id=";
  url += requestId;
  return url;
}

int fetchApprovalStatus(const String &requestId, String &rejectionReason) {
  const String statusUrl = approvalStatusUrl(requestId);
  if (statusUrl.isEmpty()) {
    Serial.println("[Approval] Provisioning URL must end with /bootstrap.");
    return -1;
  }

  const bool useHttps = statusUrl.startsWith("https://");
  if (useHttps) {
    httpsClient.setCACert(LB_PROVISIONING_CA_CERT);
  }

  HTTPClient http;
  http.setFollowRedirects(HTTPC_DISABLE_FOLLOW_REDIRECTS);
  http.setTimeout(15000);
  const bool initialized = useHttps
                               ? http.begin(httpsClient, statusUrl)
                               : http.begin(httpClient, statusUrl);
  if (!initialized) {
    Serial.println("[Approval] Could not initialize the status connection.");
    return -1;
  }

  http.addHeader("Accept", "application/json");
  http.addHeader("X-Provision-Key", LB_PROVISION_KEY);
  http.addHeader("X-Provision-Secret", LB_PROVISION_SECRET);
  const int httpStatus = http.GET();
  const String response = httpStatus == 200 ? http.getString() : String();
  http.end();

  if (httpStatus != 200) {
    Serial.printf("[Approval] Status request returned HTTP %d.\n", httpStatus);
    if (httpStatus < 0 && useHttps) {
      char tlsError[128] = {0};
      const int tlsErrorCode = httpsClient.lastError(tlsError, sizeof(tlsError));
      if (tlsErrorCode != 0) {
        Serial.printf("[Approval] HTTPS TLS transport failed (%d): %s\n",
                      tlsErrorCode, tlsError);
      }
    }
    return -1;
  }

  cJSON *root = cJSON_Parse(response.c_str());
  cJSON *statusField = cJSON_GetObjectItemCaseSensitive(root, "approval_status");
  cJSON *reasonField = cJSON_GetObjectItemCaseSensitive(root, "rejection_reason");
  int result = -1;
  if (cJSON_IsString(statusField)) {
    if (strcmp(statusField->valuestring, "pending") == 0) {
      result = 0;
    } else if (strcmp(statusField->valuestring, "approved") == 0) {
      result = 1;
    } else if (strcmp(statusField->valuestring, "rejected") == 0 &&
               cJSON_IsString(reasonField)) {
      rejectionReason = reasonField->valuestring;
      result = 2;
    }
  }
  cJSON_Delete(root);
  return result;
}

bool connectMqtt(const String &accessToken) {
  Serial.println("[MQTT] Connecting to broker over TLS...");
  unsigned long retryDelayMs = 3000;
  unsigned long lastApprovalCheckAt = 0;
  bool hasCheckedApproval = false;

  while (!mqttClient.connected()) {
    if (approvalStatus == "rejected") {
      approvalRejected = true;
      return false;
    }

    const String clientId = "esp32-" + makeDeviceCode();
    if (!mqttClient.connect(clientId.c_str(), accessToken.c_str(), accessToken.c_str())) {
      const int mqttState = mqttClient.state();
      if (mqttState == MQTT_CONNECT_BAD_CREDENTIALS ||
          mqttState == MQTT_CONNECT_UNAUTHORIZED) {
        const unsigned long now = millis();
        const bool shouldCheckApproval = approvalStatus == "pending" &&
            (!hasCheckedApproval || now - lastApprovalCheckAt >= 60000UL);
        if (shouldCheckApproval) {
          hasCheckedApproval = true;
          lastApprovalCheckAt = now;
          String rejectionReason;
          const int result = fetchApprovalStatus(provisioningRequestId, rejectionReason);
          if (result == 1) {
            if (!persistApprovalState("approved", "")) {
              Serial.println("[Approval] Could not persist the approved state.");
              return false;
            }
            Serial.println("[Approval] Device approved; retrying MQTT connection.");
          } else if (result == 2) {
            approvalRejected = true;
            if (!persistApprovalState("rejected", rejectionReason)) {
              Serial.println("[Approval] Could not persist the rejection state.");
            }
            Serial.printf("[Approval] Device rejected: %s\n", rejectionReason.c_str());
            return false;
          } else if (result == 0) {
            Serial.println("[Approval] Device is still waiting for approval.");
          } else {
            Serial.println("[Approval] Could not read the decision; MQTT will retry.");
          }
        }
      }

      if (mqttState != MQTT_CONNECT_BAD_CREDENTIALS &&
          mqttState != MQTT_CONNECT_UNAUTHORIZED) {
        char tlsError[128] = {0};
        const int tlsErrorCode = mqttTlsClient.lastError(tlsError, sizeof(tlsError));
        if (tlsErrorCode != 0) {
          Serial.printf("MQTT TLS transport failed (%d): %s\n", tlsErrorCode,
                        tlsError);
          mqttTlsRejected = true;
          return false;
        }
      }

      Serial.printf("[MQTT] Connection failed (%d); retrying.\n", mqttState);
      delay(min(retryDelayMs, 60000UL));
      retryDelayMs = min(retryDelayMs * 2, 60000UL);
    }
  }
  Serial.println("[MQTT] Connected.");
  return true;
}

void publishTelemetry() {
  simulatedTemperature = constrain(simulatedTemperature + random(-30, 31) / 100.0F,
                                   20.0F, 30.0F);
  char payload[96];
  snprintf(payload, sizeof(payload), "{\"values\":{\"temperature\":%.2f}}",
           simulatedTemperature);
  if (mqttClient.publish(telemetryTopic.c_str(), payload, false)) {
    Serial.println("[MQTT] Telemetry published.");
  } else {
    Serial.println("[MQTT] Telemetry publish failed.");
  }
}

void setup() {
  Serial.begin(115200);
  Serial.println("[Setup] ESP32 provisioning firmware started.");
  randomSeed(esp_random());
  LiteBoardSetup::connectWiFi(LB_WIFI_SSID, LB_WIFI_PASSWORD);
  if (!waitForTime()) {
    Serial.println("Could not synchronize time; TLS is stopped.");
    return;
  }

  preferences.begin("liteboard", false);
  String enrollment;
  String accessToken;
  if (!loadEnrollment(enrollment) || !configureMqtt(enrollment, accessToken)) {
    Serial.println("Enrollment could not be loaded safely.");
    return;
  }
  if (!connectMqtt(accessToken)) {
    return;
  }
}

void loop() {
  if (approvalRejected) {
    delay(5000);
    return;
  }
  if (mqttTlsRejected) {
    delay(5000);
    return;
  }
  if (!mqttClient.connected()) {
    String enrollment = preferences.getString("enrollment", "");
    String accessToken;
    if (!configureMqtt(enrollment, accessToken)) {
      delay(5000);
      return;
    }
    if (!connectMqtt(accessToken)) {
      return;
    }
  }

  mqttClient.loop();
  if (millis() - lastPublishAt >= 5000) {
    lastPublishAt = millis();
    publishTelemetry();
  }
}
