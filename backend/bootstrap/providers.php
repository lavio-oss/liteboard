<?php

use App\Providers\AppServiceProvider;
use Modules\Admin\Providers\AdminServiceProvider;
use Modules\Core\Providers\CoreServiceProvider;

return [
    AppServiceProvider::class,
    CoreServiceProvider::class,
    AdminServiceProvider::class,
];
