<?php

namespace Modules\Admin\Enums\Webhook;

enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case InFlight = 'in_flight';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Exhausted = 'exhausted';
}
