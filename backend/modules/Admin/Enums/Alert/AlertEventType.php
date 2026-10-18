<?php

namespace Modules\Admin\Enums\Alert;

enum AlertEventType: string
{
    case Opened = 'opened';
    case SeverityChanged = 'severity_changed';
    case Acknowledged = 'acknowledged';
    case Cleared = 'cleared';

    public function outboxType(): string
    {
        return 'alert.'.$this->value;
    }
}
