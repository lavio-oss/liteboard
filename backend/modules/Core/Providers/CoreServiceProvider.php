<?php

namespace Modules\Core\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Auth\PersonalAccessToken;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Policies\Tenant\TenantPolicy;

final class CoreServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Gate::policy(Tenant::class, TenantPolicy::class);

        RateLimiter::for('login', static function (Request $request): Limit {
            $email = Str::lower($request->string('email')->value());

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('device-provisioning-ip', static function (Request $request): Limit {
            return Limit::perMinute((int) config('device_provisioning.rate_limits.ip_per_minute', 30))
                ->by((string) $request->ip());
        });
    }
}
