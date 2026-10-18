<?php

namespace Modules\Admin\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Admin\Console\Alert\AlertingStatsCommand;
use Modules\Admin\Console\Webhook\DispatchWebhooksCommand;
use Modules\Admin\Models\Alert\Alert;
use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Models\Webhook\WebhookEndpoint;
use Modules\Admin\Policies\Alert\AlertPolicy;
use Modules\Admin\Policies\AlertRule\AlertRulePolicy;
use Modules\Admin\Policies\Asset\AssetPolicy;
use Modules\Admin\Policies\Device\DevicePolicy;
use Modules\Admin\Policies\DeviceProfile\DeviceProfilePolicy;
use Modules\Admin\Policies\Project\ProjectPolicy;
use Modules\Admin\Policies\Webhook\WebhookEndpointPolicy;
use Modules\Admin\Services\Webhook\DnsHostResolver;
use Modules\Admin\Services\Webhook\HostResolver;

final class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DispatchWebhooksCommand::class, AlertingStatsCommand::class]);
        }

        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Asset::class, AssetPolicy::class);
        Gate::policy(Device::class, DevicePolicy::class);
        Gate::policy(DeviceProfile::class, DeviceProfilePolicy::class);
        Gate::policy(AlertRule::class, AlertRulePolicy::class);
        Gate::policy(Alert::class, AlertPolicy::class);
        Gate::policy(WebhookEndpoint::class, WebhookEndpointPolicy::class);
    }
}
