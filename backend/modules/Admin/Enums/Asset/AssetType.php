<?php

namespace Modules\Admin\Enums\Asset;

enum AssetType: string
{
    case Site = 'site';
    case Factory = 'factory';
    case Area = 'area';
    case ProductionLine = 'production_line';
    case Station = 'station';
    case Equipment = 'equipment';
}
