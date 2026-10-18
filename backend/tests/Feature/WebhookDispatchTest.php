<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Admin\Models\Alert\OutboxEvent;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Models\Webhook\WebhookDelivery;
use Modules\Admin\Models\Webhook\WebhookEndpoint;
use Modules\Admin\Services\Webhook\HostResolver;
use Modules\Admin\Services\Webhook\WebhookDispatcher;
use Modules\Core\Models\Tenant\Tenant;

/** @var array<string, list<string>> $webhookDispatchDns */
$GLOBALS['webhookDispatchDns'] = [];

/** @return array{project: Project, endpoint: WebhookEndpoint} */
function webhookDispatchFixture(): array
{
    $GLOBALS['webhookDispatchDns'] = ['hooks.example.com' => ['93.184.216.34']];
    app()->instance(HostResolver::class, new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            return $GLOBALS['webhookDispatchDns'][$host] ?? [];
        }
    });
    Http::preventStrayRequests();
    $project = Project::factory()->for(Tenant::factory())->create();

    return ['project' => $project, 'endpoint' => WebhookEndpoint::factory()->for($project)->create()];
}

function webhookOutboxEvent(Project $project, string $type = 'alert.opened'): OutboxEvent
{
    $id = (string) Str::uuid();
    $alertId = (string) Str::uuid();
    OutboxEvent::query()->insert([
        'id' => $id,
        'project_id' => $project->getKey(),
        'event_type' => $type,
        'aggregate_id' => $alertId,
        'idempotency_key' => "alert:$alertId:$type",
        'payload' => json_encode(['id' => $id, 'type' => $type, 'project_id' => $project->getKey()]),
        'created_at' => now(),
    ]);

    return OutboxEvent::query()->findOrFail($id);
}

function webhookDispatcher(): WebhookDispatcher
{
    return app(WebhookDispatcher::class);
}

it('fans each event out once to enabled endpoints subscribed in the same project', function (): void {
    ['project' => $project, 'endpoint' => $endpoint] = webhookDispatchFixture();
    $disabled = WebhookEndpoint::factory()->for($project)->create(['enabled' => false]);
    $unsubscribed = WebhookEndpoint::factory()->for($project)->create(['event_types' => ['alert.cleared']]);
    $foreign = WebhookEndpoint::factory()->for(Project::factory()->for(Tenant::factory()))->create();
    $event = webhookOutboxEvent($project);

    expect(webhookDispatcher()->fanOut())->toBe(1)
        ->and(webhookDispatcher()->fanOut())->toBe(0);
    $event->refresh()->forceFill(['fanned_out_at' => null])->save();
    webhookDispatcher()->fanOut();

    expect(WebhookDelivery::query()->pluck('webhook_endpoint_id')->all())->toBe([$endpoint->getKey()])
        ->and($event->refresh()->fanned_out_at)->not->toBeNull();
    foreach ([$disabled, $unsubscribed, $foreign] as $skipped) {
        expect($skipped->deliveries()->exists())->toBeFalse();
    }
});

it('delivers a signed request pinned to the checked address and marks success', function (): void {
    ['project' => $project, 'endpoint' => $endpoint] = webhookDispatchFixture();
    $event = webhookOutboxEvent($project);
    Http::fake(['hooks.example.com/*' => Http::response('', 204)]);

    expect(webhookDispatcher()->dispatchOnce())->toBe(1);

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->status->value)->toBe('succeeded')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_status_code)->toBe(204)
        ->and($delivery->delivered_at)->not->toBeNull()
        ->and($delivery->attemptHistory()->count())->toBe(1);
    Http::assertSent(function (Request $request) use ($endpoint, $event, $delivery): bool {
        $timestamp = $request->header('X-LiteBoard-Timestamp')[0];
        $expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$request->body(), $endpoint->secret);

        return $request->url() === $endpoint->url
            && $request->header('X-LiteBoard-Signature')[0] === $expected
            && $request->header('X-LiteBoard-Event-Id')[0] === $event->getKey()
            && $request->header('X-LiteBoard-Delivery-Id')[0] === $delivery->getKey()
            && json_decode($request->body(), true)['id'] === $event->getKey();
    });
});

it('retries transient failures with backoff', function (Closure $response, ?int $statusCode): void {
    ['project' => $project] = webhookDispatchFixture();
    webhookOutboxEvent($project);
    Http::fake(['hooks.example.com/*' => $response]);
    $this->freezeSecond();

    webhookDispatcher()->dispatchOnce();

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->status->value)->toBe('pending')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_status_code)->toBe($statusCode)
        ->and($delivery->next_attempt_at->equalTo(now()->addSeconds(30)))->toBeTrue()
        ->and(webhookDispatcher()->dispatchOnce())->toBe(0);
})->with([
    '500' => [fn () => fn () => Http::response('', 500), 500],
    '503' => [fn () => fn () => Http::response('', 503), 503],
    '408' => [fn () => fn () => Http::response('', 408), 408],
    '429' => [fn () => fn () => Http::response('', 429), 429],
    'timeout' => [fn () => fn () => Http::failedConnection(), null],
]);

it('fails permanently on client errors and redirects without retrying', function (int $status): void {
    ['project' => $project] = webhookDispatchFixture();
    webhookOutboxEvent($project);
    Http::fake(['hooks.example.com/*' => Http::response('', $status, ['Location' => 'https://10.0.0.1/'])]);

    webhookDispatcher()->dispatchOnce();
    $this->travel(1)->days();
    webhookDispatcher()->dispatchOnce();

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->status->value)->toBe('failed')
        ->and($delivery->last_error)->toBe("http_$status")
        ->and($delivery->attempts)->toBe(1);
    Http::assertSentCount(1);
})->with([400, 401, 404, 302]);

it('exhausts after the maximum attempts and keeps every attempt', function (): void {
    ['project' => $project] = webhookDispatchFixture();
    webhookOutboxEvent($project);
    Http::fake(['hooks.example.com/*' => Http::response('', 500)]);

    for ($i = 0; $i < 10; $i++) {
        webhookDispatcher()->dispatchOnce();
        $this->travel(7)->hours();
    }

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->status->value)->toBe('exhausted')
        ->and($delivery->attempts)->toBe(8)
        ->and($delivery->attemptHistory()->pluck('attempt_no')->all())->toBe(range(1, 8));
    Http::assertSentCount(8);
});

it('reclaims deliveries whose worker died mid-flight once the lease expires', function (): void {
    ['project' => $project] = webhookDispatchFixture();
    webhookOutboxEvent($project);
    Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
    webhookDispatcher()->fanOut();

    expect(webhookDispatcher()->claim())->toHaveCount(1)
        ->and(webhookDispatcher()->claim())->toHaveCount(0);
    $this->travel(61)->seconds();
    expect(webhookDispatcher()->dispatchOnce())->toBe(1)
        ->and(WebhookDelivery::query()->sole()->status->value)->toBe('succeeded');
});

it('blocks a destination that resolves to a private address at send time', function (): void {
    ['project' => $project] = webhookDispatchFixture();
    webhookOutboxEvent($project);
    $GLOBALS['webhookDispatchDns']['hooks.example.com'] = ['169.254.169.254'];
    Http::fake();

    webhookDispatcher()->dispatchOnce();

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->status->value)->toBe('failed')
        ->and($delivery->last_error)->toBe('destination_rejected:private_address');
    Http::assertNothingSent();
});

it('never stores the signing secret in delivery history', function (): void {
    ['project' => $project, 'endpoint' => $endpoint] = webhookDispatchFixture();
    webhookOutboxEvent($project);
    Http::fake(['hooks.example.com/*' => Http::failedConnection()]);

    webhookDispatcher()->dispatchOnce();

    $delivery = WebhookDelivery::query()->with('attemptHistory')->sole();
    expect(json_encode($delivery->toArray()))->not->toContain($endpoint->secret)
        ->and($delivery->last_error)->toStartWith('connection_error');
});

it('runs a single pass from the console command', function (): void {
    ['project' => $project] = webhookDispatchFixture();
    webhookOutboxEvent($project);
    Http::fake(['hooks.example.com/*' => Http::response('', 200)]);

    $this->artisan('webhooks:dispatch', ['--once' => true])->assertSuccessful();

    expect(WebhookDelivery::query()->sole()->status->value)->toBe('succeeded');
});
