<?php

namespace Modules\Admin\Services\Alert;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Enums\Alert\AlertEventType;
use Modules\Admin\Models\Alert\Alert;
use Modules\Core\Models\User\User;

final class AlertAcknowledgementService
{
    public function __construct(private readonly AlertOutboxWriter $outboxWriter) {}

    /** Idempotent: a second acknowledge keeps the first actor/time and emits nothing. Never clears the alert. */
    public function acknowledge(Alert $alert, User $actor): Alert
    {
        return DB::transaction(function () use ($alert, $actor): Alert {
            $locked = Alert::query()->whereKey($alert->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->acknowledged_at !== null) {
                return $locked;
            }

            $now = now()->toImmutable();
            $locked->setAttribute('acknowledged_at', $now);
            $locked->setAttribute('acknowledged_by', $actor->getKey());
            $locked->save();
            $this->outboxWriter->record($locked, AlertEventType::Acknowledged, $now, $actor);

            return $locked;
        });
    }
}
