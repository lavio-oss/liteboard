<?php

namespace Modules\Admin\Enums\AlertRule;

enum AlertRuleStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
