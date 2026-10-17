<?php

namespace Modules\Admin\Enums\Telemetry;

enum TelemetryInterval: string
{
    case OneMinute = '1m';
    case FiveMinutes = '5m';
    case FifteenMinutes = '15m';
    case OneHour = '1h';
    case OneDay = '1d';

    public function seconds(): int
    {
        return match ($this) {
            self::OneMinute => 60,
            self::FiveMinutes => 300,
            self::FifteenMinutes => 900,
            self::OneHour => 3600,
            self::OneDay => 86400,
        };
    }
}
