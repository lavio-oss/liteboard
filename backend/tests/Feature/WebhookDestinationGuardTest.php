<?php

use Modules\Admin\Services\Webhook\HostResolver;
use Modules\Admin\Services\Webhook\WebhookDestinationGuard;
use Modules\Admin\Services\Webhook\WebhookDestinationRejected;

/** @param  array<string, list<string>>  $map */
function webhookGuardWith(array $map): WebhookDestinationGuard
{
    return new WebhookDestinationGuard(new class($map) implements HostResolver
    {
        /** @param  array<string, list<string>>  $map */
        public function __construct(private readonly array $map) {}

        public function resolve(string $host): array
        {
            return $this->map[$host] ?? [];
        }
    });
}

it('allows public https destinations and pins the first resolved address', function (): void {
    $guard = webhookGuardWith(['hooks.example.com' => ['93.184.216.34', '2606:2800:220:1::248']]);

    expect($guard->check('https://hooks.example.com/liteboard?x=1'))
        ->toBe(['host' => 'hooks.example.com', 'port' => 443, 'ip' => '93.184.216.34']);
});

it('rejects unsafe destinations', function (string $url, string $reason): void {
    $guard = webhookGuardWith([
        'internal.example.com' => ['10.1.2.3'],
        'mixed.example.com' => ['93.184.216.34', '192.168.1.10'],
        'localhost' => ['127.0.0.1'],
    ]);

    expect(fn () => $guard->check($url))
        ->toThrow(fn (WebhookDestinationRejected $exception) => expect($exception->reason)->toBe($reason));
})->with([
    'plain http' => ['http://hooks.example.com/x', 'scheme_not_allowed'],
    'other scheme' => ['ftp://hooks.example.com/x', 'scheme_not_allowed'],
    'credentials' => ['https://user:pass@hooks.example.com/x', 'credentials_in_url'],
    'fragment' => ['https://hooks.example.com/x#frag', 'fragment_not_allowed'],
    'non standard port' => ['https://hooks.example.com:8080/x', 'port_not_allowed'],
    'unresolvable' => ['https://nowhere.example.com/x', 'unresolvable_host'],
    'localhost' => ['https://localhost/x', 'private_address'],
    'private dns' => ['https://internal.example.com/x', 'private_address'],
    'one private record' => ['https://mixed.example.com/x', 'private_address'],
    'loopback literal' => ['https://127.0.0.1/x', 'private_address'],
    'metadata service' => ['https://169.254.169.254/latest', 'private_address'],
    'cgnat' => ['https://100.64.0.1/x', 'private_address'],
    'ipv6 loopback' => ['https://[::1]/x', 'private_address'],
    'ipv6 unique local' => ['https://[fc00::1]/x', 'private_address'],
    'ipv6 link local' => ['https://[fe80::1]/x', 'private_address'],
    'ipv4 mapped ipv6' => ['https://[::ffff:10.0.0.1]/x', 'private_address'],
    'unspecified' => ['https://0.0.0.0/x', 'private_address'],
]);

it('classifies addresses at CIDR boundaries', function (string $address, bool $blocked): void {
    expect(WebhookDestinationGuard::isBlocked($address))->toBe($blocked);
})->with([
    ['172.15.255.255', false],
    ['172.16.0.0', true],
    ['172.31.255.255', true],
    ['172.32.0.0', false],
    ['100.63.255.255', false],
    ['100.127.255.255', true],
    ['8.8.8.8', false],
    ['::ffff:8.8.8.8', false],
    ['2606:4700::1111', false],
    ['not-an-ip', true],
]);

it('permits http and private targets only when the development flag is on', function (): void {
    config(['webhooks.allow_private_destinations' => true]);

    expect(webhookGuardWith(['localhost' => ['127.0.0.1']])->check('http://localhost:9000/hook'))
        ->toBe(['host' => 'localhost', 'port' => 9000, 'ip' => '127.0.0.1']);
});
