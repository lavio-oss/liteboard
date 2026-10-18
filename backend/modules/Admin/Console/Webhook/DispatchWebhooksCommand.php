<?php

namespace Modules\Admin\Console\Webhook;

use Illuminate\Console\Command;
use Modules\Admin\Services\Webhook\WebhookDispatcher;
use Throwable;

final class DispatchWebhooksCommand extends Command
{
    protected $signature = 'webhooks:dispatch {--once : Run a single fan-out and delivery pass} {--sleep=1 : Seconds to wait when idle}';

    protected $description = 'Fan out alert outbox events to webhook endpoints and deliver them with retries.';

    private bool $stopping = false;

    public function handle(WebhookDispatcher $dispatcher): int
    {
        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT], function (): void {
                $this->stopping = true;
            });
        }

        do {
            try {
                $sent = $dispatcher->dispatchOnce();
            } catch (Throwable $exception) {
                // Keep the worker alive; the outbox and leases make the next pass pick the work up again.
                report($exception);
                $sent = 0;
            }

            if ($this->option('once')) {
                break;
            }
            if ($sent === 0 && ! $this->stopping) {
                sleep(max(1, (int) $this->option('sleep')));
            }
        } while (! $this->stopping);

        return self::SUCCESS;
    }
}
