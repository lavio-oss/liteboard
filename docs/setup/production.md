# Production deploy (Debian VPS)

nginx on the host, the rest of the stack in Docker Compose with `network_mode: host`. Install Docker Engine first: [linux.md](linux.md#install-docker-engine).

```
Internet ─443─> nginx (host)
                 ├─ /                         → 127.0.0.1:3000  Nuxt SSR
                 ├─ /ws/                      → 127.0.0.1:8100  telemetry-service WebSocket
                 ├─ /api/device-provisioning/ → 127.0.0.1:9000  php-fpm (devices skip Nuxt)
                 ├─ /mqtt/ca.txt              → /etc/liteboard/mqtt-ca.crt
                 └─ /api/internal/            → 403
          127.0.0.1:8080 (nginx)              → php-fpm, for Nuxt SSR and telemetry-service
Internet ─8883─> Mosquitto TLS (direct, not through nginx)
Internet ─1883─> mosquitto-plain playground (optional)
Mosquitto ⇄ telemetry-service ⇄ Postgres/Redis over 127.0.0.1
```

Why this layout:

- **MQTT does not pass through nginx.** A `stream` proxy would add a TCP hop and hide the device IP from Mosquitto. The broker already terminates TLS with the private CA that devices pin.
- **Host networking** removes Docker bridge NAT, conntrack and `docker-proxy` from the device → broker → telemetry → Postgres path. Performance matches a native install, and the pinned `mosquitto-go-auth` image saves building the auth plugin by hand.
- **telemetry-service runs exactly one process.** Its MQTT subscription has no `$share/` group, so a second instance would ingest every message twice.

## 1. DNS, packages, kernel, firewall

- DNS: `app.example.com` and `mqtt.example.com` → the VPS IP.
- Install Docker Engine ([linux.md](linux.md#install-docker-engine)), then `sudo apt install -y nginx certbot ufw`.
- Kernel limits: `sudo cp infrastructure/sysctl/99-liteboard.conf /etc/sysctl.d/ && sudo sysctl --system`.
- Firewall. With host networking ufw applies directly; Docker does not bypass it.

```bash
sudo ufw allow 22/tcp && sudo ufw allow 80/tcp && sudo ufw allow 443/tcp && sudo ufw allow 8883/tcp
sudo ufw allow 1883/tcp   # only if plaintext devices are needed
sudo ufw enable
```

## 2. Code and environment

```bash
sudo mkdir -p /opt/liteboard && sudo chown "$USER" /opt/liteboard
git clone <repo-url> /opt/liteboard && cd /opt/liteboard
cp .env.production.example .env.production
```

Fill every `change-me`, `APP_DOMAIN`, `MQTT_TLS_HOSTNAME` and `DEVICE_PROVISIONING_MQTT_HOST` in `.env.production`. `APP_KEY` is generated in step 5.

## 3. MQTT broker certificate

Devices verify the broker against the private CA, and the broker certificate SAN must contain `MQTT_TLS_HOSTNAME`.

```bash
bash infrastructure/mosquitto/generate-dev-certs.sh -d mqtt.example.com -i <VPS public IP>
```

- Keep `certs/ca.key` off the server: run the script on your workstation and copy only `ca.crt`, `broker/server.crt` and `broker/server.key`. Otherwise move `ca.key` away afterwards.
- Flash the generated `ESP32/AccessToken/mqtt_ca_cert.h` into the devices.
- The broker certificate is valid for 397 days. Renew it before it expires; the CA lasts 10 years, so devices don't need reflashing.
- Publish the CA for the web download: `sudo install -D -m 0644 infrastructure/mosquitto/certs/ca.crt /etc/liteboard/mqtt-ca.crt`.

## 4. nginx and HTTPS

```bash
sudo mkdir -p /var/www/certbot
sudo cp infrastructure/nginx/liteboard.conf /etc/nginx/sites-available/liteboard.conf
sudo sed -i 's/app.example.com/<your domain>/g' /etc/nginx/sites-available/liteboard.conf
sudo ln -s /etc/nginx/sites-available/liteboard.conf /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
```

The 443 block needs a certificate before nginx will start. Get it once with the standalone plugin, then let renewals run through webroot:

```bash
sudo systemctl stop nginx
sudo certbot certonly --standalone -d <your domain>
sudo nginx -t && sudo systemctl start nginx
sudo certbot renew --dry-run --webroot -w /var/www/certbot
```

## 5. Start the stack

```bash
alias dc='docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production --profile application'

dc build
dc run --rm --no-deps backend php artisan key:generate --show   # paste into APP_KEY in .env.production
dc up -d postgres redis
dc run --rm --no-deps backend php artisan migrate --force
dc up -d
```

Later deploys:

```bash
git pull && dc build && dc run --rm --no-deps backend php artisan migrate --force && dc up -d
```

## 6. Faster telemetry writes (recommended)

telemetry-service commits one transaction per MQTT message. Turning off synchronous commit for its role only removes the WAL flush wait from each message. On a crash the last few hundred milliseconds of telemetry can be lost, but the database is never corrupted. Laravel keeps the default.

```bash
dc exec postgres psql -U liteboard -d liteboard
```

```sql
CREATE ROLE liteboard_telemetry LOGIN PASSWORD 'change-me';
GRANT liteboard TO liteboard_telemetry;  -- POSTGRES_USER; inherits table privileges, including future migrations
ALTER ROLE liteboard_telemetry SET synchronous_commit = off;
```

Then set `TELEMETRY_DB_USERNAME=liteboard_telemetry` and `TELEMETRY_DB_PASSWORD` in `.env.production` and run `dc up -d telemetry-service`.

## 7. Checks

```bash
ss -tlnp     # public: 80, 443, 8883 (1883). Loopback only: 3000 8080 8100 9000 5432 6379
dc ps        # every service healthy
curl -I http://app.example.com                    # 301
curl -s 127.0.0.1:8080/api/health                 # 200
curl -s -o /dev/null -w '%{http_code}\n' https://app.example.com/api/internal/x   # 403
mosquitto_pub --cafile ca.crt -h mqtt.example.com -p 8883 -u <token> -P <token> -t ... -m '{}'
```

In the browser, the dashboard WebSocket `wss://app.example.com/ws/telemetry` should return `101`.

Logs: `dc logs -f mosquitto telemetry-service`, `sudo tail -f /var/log/nginx/error.log`.
