<?php

namespace Modules\Admin\Enums\AlertRule;

enum ComparisonOperator: string
{
    case GreaterThan = 'gt';
    case GreaterThanOrEqual = 'gte';
    case LessThan = 'lt';
    case LessThanOrEqual = 'lte';

    public function isUpward(): bool
    {
        return $this === self::GreaterThan || $this === self::GreaterThanOrEqual;
    }

    public function isInclusive(): bool
    {
        return $this === self::GreaterThanOrEqual || $this === self::LessThanOrEqual;
    }
}
