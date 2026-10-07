# ESP32 examples

The sketches are separate from the Python simulator and use the same MQTT TLS CA header.

For the full dependency, library installation, and firmware setup guide, see [LiteBoardSetup setup instructions](libraries/LiteBoardSetup/README.md).

Both sketches use the local `libraries/LiteBoardSetup` library for Wi-Fi connection and verified MQTT TLS setup. Install the required external `PubSubClient` library through Arduino Library Manager; `WiFi`, `WiFiClientSecure`, `HTTPClient`, `Preferences`, and `cJSON` come with the ESP32 Arduino core.

When compiling with Arduino CLI, pass `ESP32/libraries` as the local library directory and the FQBN for your board, for example `arduino-cli compile --libraries ESP32/libraries --fqbn <your-board-fqbn> ESP32/AccessToken`.

## Access Token

Open `AccessToken/AccessToken.ino`. Copy `device_config.h.example` to `device_config.h` and `mqtt_ca_cert.h.example` to `mqtt_ca_cert.h`, then configure Wi-Fi, the broker endpoint, Device IDs, Access Token, topic, and the public MQTT CA. The real configuration headers are ignored by Git.

## HTTPS provisioning

Open `Provisioning/Provisioning.ino`. Copy `provisioning_config.h.example` to `provisioning_config.h`, then configure its bootstrap URL and the public CA certificate trusted by that HTTPS endpoint in `provisioning_ca_cert.h`. For a local smoke test, HTTP is allowed only when the backend runs with `APP_ENV=local` and `DEVICE_PROVISIONING_ALLOW_INSECURE_HTTP=true`; this does not verify HTTPS certificate handling. For HTTPS verification and deployment, use a reachable Nginx or other HTTPS endpoint and install its trusted public CA in the firmware. The sketch uses the MQTT host returned by bootstrap by default. If a local DNS name such as `mqtt.local` does not resolve on the device network, set `LB_MQTT_HOST_OVERRIDE` to a reachable broker IP or DNS name whose value is present in the broker certificate SAN; TLS verification remains enabled. The sketch reads the MQTT CA from `../AccessToken/mqtt_ca_cert.h`, so configure that file as described above as well. See the [Access Token provisioning flow](../docs/spec/devices/devices_access_token.md#provisioning-device-flow). Local configuration and CA headers are ignored by Git.
