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
use Modules\Core\Models\User\User;

/** @return array{owner: User, tenant: Tenant, project: Project, endpoint: WebhookEndpoint, delivery: WebhookDelivery, url: string} */
function redeliverFixture(string $status): array
{
    app()->instance(HostResolver::class, new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    });
    Http::preventStrayRequests();
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();
    $endpoint = WebhookEndpoint::factory()->for($project)->create();
    $eventId = (string) Str::uuid();
    OutboxEvent::query()->insert([
        'id' => $eventId,
        'project_id' => $project->getKey(),
        'event_type' => 'alert.opened',
        'aggregate_id' => (string) Str::uuid(),
        'idempotency_key' => "alert:$eventId:opened",
        'payload' => json_encode(['id' => $eventId]),
        'created_at' => now(),
        'fanned_out_at' => now(),
    ]);
    $delivery = WebhookDelivery::factory()
        ->forEvent(OutboxEvent::query()->findOrFail($eventId), $endpoint)
        ->create(['status' => $status, 'attempts' => 8, 'last_error' => 'http_500']);
    $delivery->attemptHistory()->forceCreate([
        'attempt_no' => 1, 'started_at' => now(), 'finished_at' => now(), 'status_code' => 500, 'error' => 'http_500', 'duration_ms' => 5,
    ]);

    return [
        'owner' => $owner,
        'tenant' => $tenant,
        'project' => $project,
        'endpoint' => $endpoint,
        'delivery' => $delivery,
        'url' => "/api/tenants/$tenant->id/projects/$project->id/webhook-endpoints/{$endpoint->getKey()}/deliveries/{$delivery->getKey()}/redeliver",
    ];
}

it('re-queues an exhausted or failed delivery and resends the same event id', function (string $status): void {
    ['owner' => $owner, 'delivery' => $delivery, 'url' => $url] = redeliverFixture($status);
    Http::fake(['*' => Http::response('', 200)]);

    $this->actingAs($owner)->postJson($url)
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.attempts', 0);
    app(WebhookDispatcher::class)->dispatchOnce();

    $delivery->refresh();
    expect($delivery->status->value)->toBe('succeeded')
        ->and($delivery->attemptHistory()->pluck('attempt_no')->all())->toBe([1, 2]);
    Http::assertSent(fn (Request $request): bool => $request->header('X-LiteBoard-Event-Id')[0] === $delivery->outbox_event_id);
})->with(['exhausted', 'failed']);

it('refuses to redeliver pending, in-flight or succeeded deliveries', function (string $status): void {
    ['owner' => $owner, 'url' => $url] = redeliverFixture($status);

    $this->actingAs($owner)->postJson($url)->assertConflict();
})->with(['pending', 'in_flight', 'succeeded']);

it('lets only admins redeliver', function (): void {
    ['tenant' => $tenant, 'project' => $project, 'url' => $url] = redeliverFixture('exhausted');
    $engineer = User::factory()->create();
    $tenant->users()->attach($engineer, ['role' => 'member']);
    $project->users()->attach($engineer, ['role' => 'engineer']);

    $this->actingAs($engineer)->postJson($url)->assertForbidden();
});

it('does not resolve a delivery through another endpoint', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'project' => $project, 'delivery' => $delivery] = redeliverFixture('exhausted');
    $other = WebhookEndpoint::factory()->for($project)->create();

    $this->actingAs($owner)
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/webhook-endpoints/{$other->getKey()}/deliveries/{$delivery->getKey()}/redeliver")
        ->assertNotFound();
});
