<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Role\DefaultRole;
use Modules\Core\Enums\Role\RoleScope;
use Modules\Core\Models\Tenant\Tenant;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/** The id of a default role seeded into the tenant, picking tenant or project scope from the scope model. */
function roleId(Tenant|Project $scope, string $key): string
{
    $tenant = $scope instanceof Tenant ? $scope : $scope->tenant()->firstOrFail();
    $roleScope = $scope instanceof Tenant ? RoleScope::Tenant : RoleScope::Project;

    return (string) $tenant->defaultRole(DefaultRole::fromScopeAndKey($roleScope, $key)
        ?? throw new InvalidArgumentException("No default $roleScope->value role [$key]."))->getKey();
}
