<?php

use App\Providers\AppServiceProvider;
use Modules\Core\Providers\CoreServiceProvider;
use Modules\Masterdata\Providers\MasterdataServiceProvider;

return [
    AppServiceProvider::class,
    CoreServiceProvider::class,
    MasterdataServiceProvider::class,
];
