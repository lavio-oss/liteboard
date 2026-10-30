# Run locally on Linux (Debian / WSL2)

## Install Docker Engine

```bash
sudo apt update && sudo apt install -y ca-certificates curl git openssl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list
sudo apt update && sudo apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
sudo usermod -aG docker "$USER"   # log out and back in
docker compose version            # must be >= 2.24 (docker-compose.prod.yml uses !reset)
```

WSL2 only:

- Turn off Docker Desktop's WSL integration for this distro, so the two Docker installs don't fight over the `docker` command.
- Enable systemd: put `[boot]` and `systemd=true` in `/etc/wsl.conf`, then run `wsl --shutdown` from Windows.
- Keep the repository in the Linux filesystem (`~/projects`), not under `/mnt/c`, because bind mounts from `/mnt/c` are slow.

## Start the stack

```bash
mkdir -p ~/projects && cd ~/projects
git clone <repo-url> liteboard && cd liteboard

cp -n .env.example .env
bash infrastructure/mosquitto/generate-dev-certs.sh          # once; refuses to overwrite
docker compose run --build --rm --no-deps backend php artisan key:generate --show
# paste the key into APP_KEY in .env, set the passwords

docker compose --profile application up -d --build
```

Web: <http://localhost:3000>, API: <http://localhost:8800>, MQTT TLS: `mqtt.local:8883`. To reach the broker by name from the host, add `127.0.0.1 mqtt.local` to `/etc/hosts`. The remaining steps (seeding, simulator, ports) match [docker.md](docker.md).
