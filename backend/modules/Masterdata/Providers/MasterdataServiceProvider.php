<?php

namespace Modules\Masterdata\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Masterdata\Models\Factory\Factory;
use Modules\Masterdata\Models\ProductionLine\ProductionLine;
use Modules\Masterdata\Models\Project\Project;
use Modules\Masterdata\Policies\Factory\FactoryPolicy;
use Modules\Masterdata\Policies\ProductionLine\ProductionLinePolicy;
use Modules\Masterdata\Policies\Project\ProjectPolicy;

final class MasterdataServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Factory::class, FactoryPolicy::class);
        Gate::policy(ProductionLine::class, ProductionLinePolicy::class);
    }
}
