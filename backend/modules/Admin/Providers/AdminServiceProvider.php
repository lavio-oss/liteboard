<?php

namespace Modules\Admin\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Policies\Asset\AssetPolicy;
use Modules\Admin\Policies\Device\DevicePolicy;
use Modules\Admin\Policies\Project\ProjectPolicy;

final class AdminServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Asset::class, AssetPolicy::class);
        Gate::policy(Device::class, DevicePolicy::class);
    }
}
