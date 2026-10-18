<?php

namespace Modules\Admin\Services\Webhook;

/**
 * SSRF guard. Runs when an endpoint is saved and again before every send, then the caller pins
 * the connection to the checked address so a DNS change cannot redirect the request.
 */
final class WebhookDestinationGuard
{
    private const BLOCKED_CIDRS = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '64:ff9b::/96', '100::/64', '2001::/32', '2001:db8::/32', '2002::/16',
        'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @return array{host: string, port: int, ip: string}
     *
     * @throws WebhookDestinationRejected
     */
    public function check(string $url): array
    {
        $parts = parse_url($url);
        $allowPrivate = (bool) config('webhooks.allow_private_destinations');

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new WebhookDestinationRejected('invalid_url');
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && ! ($allowPrivate && $scheme === 'http')) {
            throw new WebhookDestinationRejected('scheme_not_allowed');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new WebhookDestinationRejected('credentials_in_url');
        }
        if (isset($parts['fragment'])) {
            throw new WebhookDestinationRejected('fragment_not_allowed');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        /** @var list<int> $allowedPorts */
        $allowedPorts = config('webhooks.allowed_ports');
        if (! $allowPrivate && ! in_array($port, $allowedPorts, true)) {
            throw new WebhookDestinationRejected('port_not_allowed');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new WebhookDestinationRejected('unresolvable_host');
        }

        // Every resolved address must be public; otherwise a round-robin record could smuggle in a private one.
        foreach ($addresses as $address) {
            if (! $allowPrivate && self::isBlocked($address)) {
                throw new WebhookDestinationRejected('private_address');
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    public static function isBlocked(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return true;
        }

        // IPv4-mapped IPv6 (::ffff:a.b.c.d) is judged by the embedded IPv4 address.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        foreach (self::BLOCKED_CIDRS as $cidr) {
            [$network, $bits] = explode('/', $cidr);
            $networkPacked = inet_pton($network);

            if ($networkPacked !== false && strlen($networkPacked) === strlen($packed)
                && self::matches($packed, $networkPacked, (int) $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $address, string $network, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        $remaining = $bits % 8;
        if ($remaining === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
