<?php

namespace Modules\Admin\Services\Webhook;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Admin\Enums\Webhook\WebhookDeliveryStatus;
use Modules\Admin\Models\Alert\OutboxEvent;
use Modules\Admin\Models\Webhook\WebhookDelivery;
use Modules\Admin\Models\Webhook\WebhookDeliveryAttempt;
use Modules\Admin\Models\Webhook\WebhookEndpoint;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Outbox → deliveries → HTTP. HTTP runs outside any transaction, so a slow receiver never holds
 * locks; a crashed worker's lease expires and the delivery is claimed again.
 */
final class WebhookDispatcher
{
    public function __construct(private readonly WebhookDestinationGuard $guard) {}

    /** @return int the number of delivery attempts made */
    public function dispatchOnce(): int
    {
        $this->fanOut();
        $deliveries = $this->claim();

        foreach ($deliveries as $delivery) {
            $this->deliver($delivery);
        }

        return $deliveries->count();
    }

    public function fanOut(): int
    {
        return DB::transaction(function (): int {
            $events = OutboxEvent::query()
                ->whereNull('fanned_out_at')
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($this->batchSize())
                ->lock('for update skip locked')
                ->get();
            $now = now()->toImmutable();

            foreach ($events as $event) {
                $rows = WebhookEndpoint::query()
                    ->where('project_id', $event->project_id)
                    ->where('enabled', true)
                    ->get()
                    ->filter(static fn (WebhookEndpoint $endpoint): bool => in_array($event->event_type, $endpoint->event_types, true)
                        && in_array(data_get($event->payload, 'alert.rule_id'), $endpoint->alert_rule_ids, true))
                    ->map(static fn (WebhookEndpoint $endpoint): array => [
                        'id' => (string) Str::uuid(),
                        'project_id' => $event->project_id,
                        'outbox_event_id' => $event->getKey(),
                        'webhook_endpoint_id' => $endpoint->getKey(),
                        'status' => WebhookDeliveryStatus::Pending->value,
                        'attempts' => 0,
                        'next_attempt_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])
                    ->values()
                    ->all();

                if ($rows !== []) {
                    WebhookDelivery::query()->insertOrIgnore($rows);
                }

                $event->setAttribute('fanned_out_at', $now);
                $event->save();
            }

            return $events->count();
        });
    }

    /** @return Collection<int, WebhookDelivery> */
    public function claim(): Collection
    {
        return DB::transaction(function (): Collection {
            $now = now()->toImmutable();
            $deliveries = WebhookDelivery::query()
                ->where(static function ($query) use ($now): void {
                    $query->where(static function ($pending) use ($now): void {
                        $pending->where('status', WebhookDeliveryStatus::Pending->value)->where('next_attempt_at', '<=', $now);
                    })->orWhere(static function ($abandoned) use ($now): void {
                        $abandoned->where('status', WebhookDeliveryStatus::InFlight->value)->where('lease_expires_at', '<', $now);
                    });
                })
                ->orderBy('next_attempt_at')
                ->limit($this->batchSize())
                ->lock('for update skip locked')
                ->get();

            foreach ($deliveries as $delivery) {
                $delivery->setAttribute('status', WebhookDeliveryStatus::InFlight);
                $delivery->setAttribute('lease_expires_at', $now->addSeconds((int) config('webhooks.lease_seconds')));
                $delivery->save();
            }

            return $deliveries;
        });
    }

    public function deliver(WebhookDelivery $delivery): void
    {
        $endpoint = $delivery->endpoint()->firstOrFail();
        $event = $delivery->outboxEvent()->firstOrFail();
        $startedAt = now()->toImmutable();
        $started = hrtime(true);
        $statusCode = null;
        $error = null;
        $retryable = false;

        if (! $endpoint->enabled) {
            $error = 'endpoint_disabled';
        } else {
            try {
                $destination = $this->guard->check($endpoint->url);
                $body = json_encode($event->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $timestamp = (string) $startedAt->getTimestamp();
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'connect_timeout' => (int) config('webhooks.connect_timeout_seconds'),
                    'timeout' => (int) config('webhooks.timeout_seconds'),
                    'curl' => [CURLOPT_RESOLVE => ["{$destination['host']}:{$destination['port']}:{$destination['ip']}"]],
                ])
                    ->withHeaders([
                        'User-Agent' => 'LiteBoard-Webhooks/1',
                        'X-LiteBoard-Event-Id' => (string) $event->getKey(),
                        'X-LiteBoard-Event-Type' => $event->event_type,
                        'X-LiteBoard-Delivery-Id' => (string) $delivery->getKey(),
                        'X-LiteBoard-Timestamp' => $timestamp,
                        'X-LiteBoard-Signature' => 'v1='.hash_hmac('sha256', "$timestamp.$body", $endpoint->secret),
                    ])
                    ->withBody($body, 'application/json')
                    ->post($endpoint->url);
                $statusCode = $response->status();

                if (! $response->successful()) {
                    $error = "http_$statusCode";
                    $retryable = $statusCode === 408 || $statusCode === 429 || $statusCode >= 500;
                }
            } catch (WebhookDestinationRejected $exception) {
                $error = 'destination_rejected:'.$exception->reason;
            } catch (ConnectionException $exception) {
                $error = 'connection_error: '.$exception->getMessage();
                $retryable = true;
            }
        }

        $this->record($delivery, $startedAt, $statusCode, $error, $retryable, intdiv(hrtime(true) - $started, 1_000_000));
    }

    /** Only failed or exhausted deliveries can be sent again; history and the event id are kept. */
    public function redeliver(WebhookDelivery $delivery): WebhookDelivery
    {
        return DB::transaction(function () use ($delivery): WebhookDelivery {
            $locked = WebhookDelivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [WebhookDeliveryStatus::Failed, WebhookDeliveryStatus::Exhausted], true)) {
                throw new ConflictHttpException('WEBHOOK_DELIVERY_NOT_REDELIVERABLE');
            }

            $locked->setAttribute('status', WebhookDeliveryStatus::Pending);
            $locked->setAttribute('attempts', 0);
            $locked->setAttribute('next_attempt_at', now()->toImmutable());
            $locked->setAttribute('lease_expires_at', null);
            $locked->save();

            return $locked;
        });
    }

    private function record(
        WebhookDelivery $delivery,
        CarbonImmutable $startedAt,
        ?int $statusCode,
        ?string $error,
        bool $retryable,
        int $durationMs,
    ): void {
        DB::transaction(function () use ($delivery, $startedAt, $statusCode, $error, $retryable, $durationMs): void {
            $locked = WebhookDelivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();
            $now = now()->toImmutable();
            $error = $error === null ? null : Str::limit($error, 480, '');
            // `attempts` restarts on redeliver; attempt_no stays monotonic for the history.
            $attempts = $locked->attempts + 1;
            $maxAttempts = (int) config('webhooks.max_attempts');
            /** @var list<int> $backoff */
            $backoff = config('webhooks.backoff_seconds');

            $attempt = new WebhookDeliveryAttempt;
            $attempt->forceFill([
                'webhook_delivery_id' => $locked->getKey(),
                'attempt_no' => (int) $locked->attemptHistory()->max('attempt_no') + 1,
                'started_at' => $startedAt,
                'finished_at' => $now,
                'status_code' => $statusCode,
                'error' => $error,
                'duration_ms' => $durationMs,
            ])->save();

            $status = match (true) {
                $error === null => WebhookDeliveryStatus::Succeeded,
                ! $retryable => WebhookDeliveryStatus::Failed,
                $attempts >= $maxAttempts => WebhookDeliveryStatus::Exhausted,
                default => WebhookDeliveryStatus::Pending,
            };

            $locked->setAttribute('status', $status);
            $locked->setAttribute('attempts', $attempts);
            $locked->setAttribute('lease_expires_at', null);
            $locked->setAttribute('last_status_code', $statusCode);
            $locked->setAttribute('last_error', $error);
            if ($status === WebhookDeliveryStatus::Succeeded) {
                $locked->setAttribute('delivered_at', $now);
            }
            if ($status === WebhookDeliveryStatus::Pending) {
                $locked->setAttribute('next_attempt_at', $now->addSeconds($backoff[min($attempts, count($backoff)) - 1]));
            }
            $locked->save();
        });
    }

    private function batchSize(): int
    {
        return (int) config('webhooks.batch_size');
    }
}
