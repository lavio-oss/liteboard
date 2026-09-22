<?php

namespace Modules\Masterdata\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Masterdata\Models\Asset\Asset;
use Modules\Masterdata\Models\Project\Project;
use Modules\Masterdata\Policies\Asset\AssetPolicy;
use Modules\Masterdata\Policies\Project\ProjectPolicy;

final class MasterdataServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Asset::class, AssetPolicy::class);
    }
}
