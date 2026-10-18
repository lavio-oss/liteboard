<?php

namespace Modules\Admin\Enums\AlertRule;

enum AlertSeverity: string
{
    case Critical = 'critical';
    case Major = 'major';
    case Minor = 'minor';
    case Warning = 'warning';
}
