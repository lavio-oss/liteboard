<?php

namespace Modules\Admin\Services\Webhook;

use RuntimeException;

final class WebhookDestinationRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Webhook destination rejected: $reason");
    }
}
