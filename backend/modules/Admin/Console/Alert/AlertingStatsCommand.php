<?php

namespace Modules\Admin\Console\Alert;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Enums\Webhook\WebhookDeliveryStatus;
use Modules\Admin\Models\Alert\OutboxEvent;
use Modules\Admin\Models\Webhook\WebhookDelivery;

/**
 * IT-5 acceptance metrics: evaluation latency, outbox age, delivery outcomes, retries and recovery.
 */
final class AlertingStatsCommand extends Command
{
    protected $signature = 'alerting:stats {--minutes=60 : Window for evaluation latency}';

    protected $description = 'Print rule evaluation, outbox and webhook delivery metrics.';

    public function handle(): int
    {
        $now = now()->toImmutable();
        $since = $now->subMinutes(max(1, (int) $this->option('minutes')));

        $latencies = DB::table('telemetry_evaluation_jobs')
            ->whereNotNull('evaluated_at')
            ->where('evaluated_at', '>=', $since)
            ->get(['received_at', 'evaluated_at'])
            ->map(static fn (object $row): float => self::millisecondsBetween($row->received_at, $row->evaluated_at))
            ->sort()
            ->values()
            ->all();
        $pending = DB::table('telemetry_evaluation_jobs')->whereNull('evaluated_at');
        $oldestPending = (clone $pending)->min('received_at');
        $oldestOutbox = OutboxEvent::query()->whereNull('fanned_out_at')->min('created_at');
        $byStatus = WebhookDelivery::query()
            ->select('status', DB::raw('COUNT(*) AS total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $this->table(['metric', 'value'], [
            ['evaluations (window)', count($latencies)],
            ['evaluation latency p50 ms', self::percentile($latencies, 0.50)],
            ['evaluation latency p95 ms', self::percentile($latencies, 0.95)],
            ['pending evaluation jobs', $pending->count()],
            ['oldest pending job age s', self::ageSeconds($oldestPending, $now)],
            ['outbox pending fan-out', OutboxEvent::query()->whereNull('fanned_out_at')->count()],
            ['oldest outbox age s', self::ageSeconds($oldestOutbox, $now)],
            ...array_map(
                static fn (WebhookDeliveryStatus $status): array => ["deliveries $status->value", (int) ($byStatus[$status->value] ?? 0)],
                WebhookDeliveryStatus::cases(),
            ),
            ['deliveries retried', WebhookDelivery::query()->where('attempts', '>', 1)->count()],
            ['deliveries recovered after retry', WebhookDelivery::query()
                ->where('status', WebhookDeliveryStatus::Succeeded->value)
                ->where('attempts', '>', 1)
                ->count()],
        ]);

        return self::SUCCESS;
    }

    /** @param  list<float>  $sorted */
    private static function percentile(array $sorted, float $quantile): string
    {
        if ($sorted === []) {
            return '-';
        }

        return number_format($sorted[(int) ceil($quantile * count($sorted)) - 1], 1, '.', '');
    }

    private static function millisecondsBetween(mixed $from, mixed $to): float
    {
        return (float) CarbonImmutable::parse((string) $from)->diffInMilliseconds(CarbonImmutable::parse((string) $to));
    }

    private static function ageSeconds(mixed $timestamp, CarbonImmutable $now): string
    {
        return $timestamp === null ? '-' : (string) (int) CarbonImmutable::parse((string) $timestamp)->diffInSeconds($now);
    }
}
