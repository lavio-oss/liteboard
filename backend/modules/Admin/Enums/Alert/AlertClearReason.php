<?php

namespace Modules\Admin\Enums\Alert;

enum AlertClearReason: string
{
    case ConditionCleared = 'condition_cleared';
    case DeviceOffline = 'device_offline';
    case RuleArchived = 'rule_archived';
}
