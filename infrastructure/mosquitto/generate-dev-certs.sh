#!/usr/bin/env bash
# Linux/macOS twin of generate-dev-certs.ps1. Keep both in sync.
# Usage: ./generate-dev-certs.sh [-d dns-name]... [-i ip-address]...
# Without -d/-i the defaults below are used; any -d/-i replaces the defaults of that kind.
set -euo pipefail

dns_names=()
ip_addresses=()
while getopts 'd:i:' option; do
    case "$option" in
        d) dns_names+=("$OPTARG") ;;
        i) ip_addresses+=("$OPTARG") ;;
        *) echo "Usage: $0 [-d dns-name]... [-i ip-address]..." >&2; exit 1 ;;
    esac
done
[ ${#dns_names[@]} -eq 0 ] && dns_names=(mqtt.local mosquitto localhost)
[ ${#ip_addresses[@]} -eq 0 ] && ip_addresses=(127.0.0.1 192.168.100.102)

fail() { echo "$1" >&2; exit 1; }

command -v openssl >/dev/null || fail 'openssl is required.'
version=$(openssl version | sed -nE 's/^OpenSSL ([0-9]+)\.([0-9]+)\.([0-9]+).*/\1 \2 \3/p')
[ -n "$version" ] || fail 'Unable to determine the OpenSSL version.'
read -r major minor patch <<<"$version"
if [ "$major" -lt 1 ] || { [ "$major" -eq 1 ] && { [ "$minor" -lt 1 ] || { [ "$minor" -eq 1 ] && [ "$patch" -lt 1 ]; }; }; }; then
    fail 'OpenSSL 1.1.1 or newer is required to create the development certificates.'
fi

script_dir=$(cd "$(dirname "$0")" && pwd)
cert_dir="$script_dir/certs"
broker_dir="$cert_dir/broker"
ca_key="$cert_dir/ca.key"
ca_crt="$cert_dir/ca.crt"
broker_key="$broker_dir/server.key"
broker_csr="$broker_dir/server.csr"
broker_crt="$broker_dir/server.crt"
ext_file="$broker_dir/server.ext"
serial_file="$cert_dir/ca.srl"
firmware_header="$script_dir/../../ESP32/AccessToken/mqtt_ca_cert.h"
public_ca_dir="$script_dir/../../web/public/mqtt"
public_ca="$public_ca_dir/ca.txt"

existing=()
for file in "$ca_key" "$ca_crt" "$broker_key" "$broker_crt" "$firmware_header" "$public_ca"; do
    [ -e "$file" ] && existing+=("$file")
done
[ ${#existing[@]} -eq 0 ] || fail "Development certificate files already exist. Refusing to overwrite: ${existing[*]}"

for name in "${dns_names[@]}"; do
    [[ "$name" =~ ^[A-Za-z0-9.-]+$ ]] || fail "Invalid DNS SAN value: $name"
done
for address in "${ip_addresses[@]}"; do
    [[ "$address" =~ ^[0-9A-Fa-f:.]+$ ]] || fail "Invalid IP SAN value: $address"
done

mkdir -p "$broker_dir"

{
    echo 'basicConstraints=critical,CA:FALSE'
    echo 'keyUsage=critical,digitalSignature,keyEncipherment'
    echo 'extendedKeyUsage=serverAuth'
    echo 'subjectAltName=@alt_names'
    echo '[alt_names]'
    for index in "${!dns_names[@]}"; do echo "DNS.$((index + 1)) = ${dns_names[$index]}"; done
    for index in "${!ip_addresses[@]}"; do echo "IP.$((index + 1)) = ${ip_addresses[$index]}"; done
} >"$ext_file"

openssl req -x509 -newkey rsa:4096 -sha256 -nodes -days 3650 \
    -keyout "$ca_key" -out "$ca_crt" \
    -subj '/CN=LiteBoard Development MQTT CA' \
    -addext 'basicConstraints=critical,CA:TRUE,pathlen:0' \
    -addext 'keyUsage=critical,keyCertSign,cRLSign' \
    || fail 'OpenSSL failed to create the development CA.'

openssl req -new -newkey rsa:2048 -sha256 -nodes \
    -keyout "$broker_key" -out "$broker_csr" \
    -subj "/CN=${dns_names[0]}" \
    || fail 'OpenSSL failed to create the broker private key and certificate request.'

openssl x509 -req -in "$broker_csr" \
    -CA "$ca_crt" -CAkey "$ca_key" -CAcreateserial -CAserial "$serial_file" \
    -out "$broker_crt" -days 397 -sha256 -extfile "$ext_file" \
    || fail 'OpenSSL failed to sign the broker certificate.'

# Mosquitto runs as a non-root user inside the container and must read the key.
chmod 0644 "$broker_key"

mkdir -p "$(dirname "$firmware_header")"
printf '#pragma once\n\nstatic const char LB_MQTT_CA_CERT[] = R"LBCA(\n%s\n)LBCA";\n' "$(cat "$ca_crt")" >"$firmware_header"
mkdir -p "$public_ca_dir"
cp "$ca_crt" "$public_ca"

echo "Development MQTT certificates created under $cert_dir"
echo "Broker SAN DNS: ${dns_names[*]}"
echo "Broker SAN IP: ${ip_addresses[*]}"
echo 'The public CA certificate is available in the device connection guide as liteboard-mqtt-ca.txt.'
echo 'The CA private key is local-only. Do not copy it into a container or firmware.'
