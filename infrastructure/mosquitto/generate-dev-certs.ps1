param(
    [string[]]$DnsName = @('mqtt.local', 'mosquitto', 'localhost'),
    [string[]]$IpAddress = @('127.0.0.1', '192.168.100.102')
)

$ErrorActionPreference = 'Stop'

$opensslCommand = Get-Command openssl -ErrorAction Stop
$opensslVersion = & $opensslCommand.Source version
if ($LASTEXITCODE -ne 0 -or $opensslVersion -notmatch 'OpenSSL\s+(\d+)\.(\d+)\.(\d+)') {
    throw 'Unable to determine the OpenSSL version.'
}
$opensslMajor = [int]$Matches[1]
$opensslMinor = [int]$Matches[2]
$opensslPatch = [int]$Matches[3]
if ($opensslMajor -lt 1 -or ($opensslMajor -eq 1 -and ($opensslMinor -lt 1 -or ($opensslMinor -eq 1 -and $opensslPatch -lt 1)))) {
    throw 'OpenSSL 1.1.1 or newer is required to create the development certificates.'
}

$certificateDirectory = Join-Path $PSScriptRoot 'certs'
$brokerDirectory = Join-Path $certificateDirectory 'broker'
$caKeyFile = Join-Path $certificateDirectory 'ca.key'
$caCertificateFile = Join-Path $certificateDirectory 'ca.crt'
$brokerKeyFile = Join-Path $brokerDirectory 'server.key'
$brokerCsrFile = Join-Path $brokerDirectory 'server.csr'
$brokerCertificateFile = Join-Path $brokerDirectory 'server.crt'
$extensionsFile = Join-Path $brokerDirectory 'server.ext'
$serialFile = Join-Path $certificateDirectory 'ca.srl'
$firmwareCaHeader = Join-Path $PSScriptRoot '../../simulator/Esp32/Base/mqtt_ca_cert.h'
$publicCaDirectory = Join-Path $PSScriptRoot '../../web/public/mqtt'
$publicCaFile = Join-Path $publicCaDirectory 'ca.txt'

$existingFiles = @(
    $caKeyFile,
    $caCertificateFile,
    $brokerKeyFile,
    $brokerCertificateFile,
    $firmwareCaHeader,
    $publicCaFile
) | Where-Object { Test-Path -LiteralPath $_ }

if ($existingFiles.Count -gt 0) {
    throw "Development certificate files already exist. Refusing to overwrite: $($existingFiles -join ', ')"
}

if ($DnsName.Count -eq 0 -and $IpAddress.Count -eq 0) {
    throw 'Provide at least one DNS name or IP address for the broker certificate SAN.'
}

foreach ($name in $DnsName) {
    if ($name -notmatch '^[A-Za-z0-9.-]+$') {
        throw "Invalid DNS SAN value: $name"
    }
}

foreach ($address in $IpAddress) {
    $parsedAddress = $null
    if (-not [System.Net.IPAddress]::TryParse($address, [ref]$parsedAddress)) {
        throw "Invalid IP SAN value: $address"
    }
}

New-Item -ItemType Directory -Path $brokerDirectory -Force | Out-Null

$sanLines = [System.Collections.Generic.List[string]]::new()
for ($index = 0; $index -lt $DnsName.Count; $index++) {
    $sanLines.Add("DNS.$($index + 1) = $($DnsName[$index])")
}
for ($index = 0; $index -lt $IpAddress.Count; $index++) {
    $sanLines.Add("IP.$($index + 1) = $($IpAddress[$index])")
}

$extensionConfiguration = @(
    'basicConstraints=critical,CA:FALSE',
    'keyUsage=critical,digitalSignature,keyEncipherment',
    'extendedKeyUsage=serverAuth',
    'subjectAltName=@alt_names',
    '[alt_names]'
) + $sanLines.ToArray()
[System.IO.File]::WriteAllLines($extensionsFile, $extensionConfiguration, [System.Text.UTF8Encoding]::new($false))

& $opensslCommand.Source req -x509 -newkey rsa:4096 -sha256 -nodes -days 3650 `
    -keyout $caKeyFile -out $caCertificateFile `
    -subj '/CN=LiteBoard Development MQTT CA' `
    -addext 'basicConstraints=critical,CA:TRUE,pathlen:0' `
    -addext 'keyUsage=critical,keyCertSign,cRLSign'
if ($LASTEXITCODE -ne 0) {
    throw 'OpenSSL failed to create the development CA.'
}

& $opensslCommand.Source req -new -newkey rsa:2048 -sha256 -nodes `
    -keyout $brokerKeyFile -out $brokerCsrFile `
    -subj "/CN=$(if ($DnsName.Count -gt 0) { $DnsName[0] } else { $IpAddress[0] })"
if ($LASTEXITCODE -ne 0) {
    throw 'OpenSSL failed to create the broker private key and certificate request.'
}

& $opensslCommand.Source x509 -req -in $brokerCsrFile `
    -CA $caCertificateFile -CAkey $caKeyFile -CAcreateserial -CAserial $serialFile `
    -out $brokerCertificateFile -days 397 -sha256 -extfile $extensionsFile
if ($LASTEXITCODE -ne 0) {
    throw 'OpenSSL failed to sign the broker certificate.'
}

$caPem = (Get-Content -Raw -Encoding UTF8 -LiteralPath $caCertificateFile).Trim()
$headerContent = @"
#pragma once

static const char LB_MQTT_CA_CERT[] = R"LBCA(
$caPem
)LBCA";
"@
[System.IO.File]::WriteAllText($firmwareCaHeader, $headerContent, [System.Text.UTF8Encoding]::new($false))
New-Item -ItemType Directory -Path $publicCaDirectory -Force | Out-Null
Copy-Item -LiteralPath $caCertificateFile -Destination $publicCaFile

Write-Output "Development MQTT certificates created under $certificateDirectory"
Write-Output "Broker SAN DNS: $($DnsName -join ', ')"
Write-Output "Broker SAN IP: $($IpAddress -join ', ')"
Write-Output 'The public CA certificate is available in the device connection guide as liteboard-mqtt-ca.txt.'
Write-Output 'The CA private key is local-only. Do not copy it into a container or firmware.'
