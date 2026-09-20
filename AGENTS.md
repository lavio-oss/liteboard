# Important Things

* This repository contains two main applications:

  * `frontend/` — frontend application.
  * `backend/` — Laravel backend application.
* This is a multi-tenant application. For every task, first classify central versus tenant-owned data and reason about tenant isolation across data access, authorization, validation, cache, filesystem, queues, events, routes, tests, and UI/API flows. Never assume single-tenant behavior; use `$laravel-multi-tenancy` and preserve the organization context.
* Scope rules for nested applications:

  * This root `AGENTS.md` defines the shared repository baseline.
  * When a task touches files under `frontend/`, always read and follow `frontend/AGENTS.md` first.
  * When a task touches files under `backend/`, always read and follow `backend/AGENTS.md` first when present.
  * Treat the nearest applicable subdirectory `AGENTS.md` as the primary project guide for that area.
  * If a subdirectory rule conflicts with a root rule, prefer the more specific subdirectory `AGENTS.md` for files in that subtree.
  * Rules that do not conflict remain cumulative.
* The Laravel backend lives in `backend/`.
* Backend domain code lives in `backend/modules/` (`Core`, later `Masterdata`, `Inventory`, and other domain modules).
* `backend/app/` is host-level tooling only, such as providers and PHPStan integration. Do not place domain/business code there.
* Run backend commands from `backend/`. Do not add root-level Composer or Artisan wrappers.
* Module layout: use `Module/{Concern}/{SubModule}/{Class}.php` for standard concerns.
* Every SubModule should follow the repository module structure consistently, including the expected concern directories and `Routes/<submodule-kebab>.php`.
* `Core/Shared` is the sole cross-cutting exception unless the project documentation explicitly defines another one.
* Read `docs/modules.md` and use `$module-structure` before creating or reorganizing backend Modules or SubModules.
* Run `cd backend && composer stan` and `cd backend && composer test` only when backend files change.
* Run `cd backend && composer pint` only after both PHPStan and tests pass.
* Migrations are forward-only: never declare `down()`. Reverse schema changes with a later compensating migration, never with rollback commands on a deployed database.
* The frontend application lives in `frontend/`.
* When frontend files change, run the frontend validation/check command defined by `frontend/AGENTS.md` or the frontend project configuration.
* Frontend API calls must use the established API/request layer inside the frontend application.
* Before adding, changing, or using frontend i18n translation keys or namespaces, read and follow [docs/frontend/translate.md](docs/frontend/translate.md).
* Before changing any API flow, read and follow [docs/DATA_CONTRACT_INTEGRITY_RULE.md](docs/DATA_CONTRACT_INTEGRITY_RULE.md).
* When request input is involved, also read and follow [docs/Request_Handling.md](docs/Request_Handling.md).
* For any new feature or behavioral change, write the corresponding tests and make sure they pass before finishing.
* Only fix failing tests related to the current task. Do not expand the scope to unrelated failures unless explicitly requested.
* Do not add tiny internal helper functions that only wrap a single ternary, property access, translation lookup, or one-use branch.
* Inline simple view-only formatting unless the logic is genuinely reused or removes substantial complexity.
* When using Playwright MCP, use it as a browser-access tool rather than generating Playwright test code unless explicitly requested.
* Use `cd backend && php artisan db:table users` for table schema inspection instead of inferring the active schema from models or old migrations.
* Use non-interactive Tinker with `--execute=...` from `backend/` for quick checks.
* Prefer single quotes around PHP snippets to avoid shell expansion, for example:

```bash
cd backend && php artisan tinker --execute='use Modules\Core\Models\User\User; var_dump(User::query()->first());'
```

* Always read and follow [docs/DATA_CONTRACT_INTEGRITY_RULE.md](docs/DATA_CONTRACT_INTEGRITY_RULE.md) before any schema, API, model, or UI work. Do not skip it.
* For Laravel PHP type-safe request, array, config, nested input, nullable scalar, or PHPStan-sensitive data access, use the local skill at `.agents/skills/typesafe-laravel-php/SKILL.md`.
* When unsure about Laravel behavior, APIs, conventions, or framework features, read the relevant documentation under `docs/laravel/` before deciding or coding.

# Repository Structure

```text
root/
├── AGENTS.md
│
├── frontend/
│   ├── AGENTS.md
│   └── ...
│
├── backend/
│   ├── AGENTS.md
│   ├── app/
│   ├── modules/
│   └── ...
│
├── docs/
└── .agents/
    └── skills/
```

The root `AGENTS.md` contains rules shared across the whole repository.

Application-specific instructions belong in:

```text
frontend/AGENTS.md
backend/AGENTS.md
```

For frontend files, instruction precedence is:

```text
root/AGENTS.md
        ↓
frontend/AGENTS.md
        ↓
nearest nested AGENTS.md, if present
        ↓
task context
```

For backend files:

```text
root/AGENTS.md
        ↓
backend/AGENTS.md
        ↓
nearest nested AGENTS.md, if present
        ↓
task context
```

More specific instructions override broader instructions only when they conflict.

# Multi-Tenancy

Multi-tenancy is a system-wide invariant, not only a database concern.

Before implementing or modifying a feature, determine whether the relevant data is:

* Central data — shared across tenants or responsible for coordinating tenants.
* Tenant-owned data — isolated within a specific organization/tenant context.

Evaluate tenant isolation across:

* Eloquent queries and relationships.
* Authorization and policies.
* Request validation.
* Route model binding.
* Cache keys and cache invalidation.
* Filesystem paths.
* Jobs and queues.
* Events and listeners.
* Notifications.
* API responses.
* Frontend state.
* UI flows.
* Tests and test fixtures.

Never assume that an ID alone uniquely identifies a tenant-owned resource across organizations.

Preserve the active organization context when dispatching work across synchronous and asynchronous boundaries.

Use the project's `$laravel-multi-tenancy` integration rather than implementing parallel tenancy behavior manually.

# Backend

The Laravel backend lives under:

```text
backend/
```

Domain code belongs under:

```text
backend/modules/
```

Examples:

```text
backend/modules/Core/
backend/modules/Masterdata/
backend/modules/Inventory/
```

`backend/app/` is reserved for host-application concerns such as:

* Service providers.
* Framework integrations.
* PHPStan integration.
* Application-level infrastructure that does not belong to a business domain.

Do not move domain logic into `backend/app/` merely because standard Laravel applications normally place application code there.

# Backend Module Structure

Use the repository's modular structure consistently.

Standard concern layout follows:

```text
Module/{Concern}/{SubModule}/{Class}.php
```

Example:

```text
Inventory/
├── Actions/
│   └── Warehouse/
├── Models/
│   └── Warehouse/
├── Requests/
│   └── Warehouse/
├── Resources/
│   └── Warehouse/
└── Routes/
    └── warehouse.php
```

Every SubModule should keep the expected skeleton even when some concerns are currently empty.

Use `.gitkeep` where an empty concern directory must remain tracked.

Route files use kebab-case naming:

```text
Routes/<submodule-kebab>.php
```

`Core/Shared` is the designated cross-cutting exception.

Before changing module organization, read:

```text
docs/modules.md
```

and follow the local `$module-structure` skill.

# Frontend

The frontend application lives under:

```text
frontend/
```

Before modifying frontend code, read:

```text
frontend/AGENTS.md
```

The frontend-specific `AGENTS.md` should define details such as:

* Framework conventions.
* Component structure.
* State management.
* API organization.
* Validation commands.
* Styling conventions.
* Frontend-specific tests.
* Naming conventions.

Do not assume frontend framework conventions from the root repository guide when `frontend/AGENTS.md` provides more specific instructions.

Frontend API calls must use the project's established API/request layer rather than bypassing it directly from components unless the frontend guide explicitly permits otherwise.

Before changing translation namespaces or translation keys, read:

```text
docs/frontend/translate.md
```

# Data Contract Integrity

[docs/DATA_CONTRACT_INTEGRITY_RULE.md](docs/DATA_CONTRACT_INTEGRITY_RULE.md) is mandatory reading before changes involving:

* Database schemas.
* Migrations.
* Eloquent models.
* API requests.
* API responses.
* Resources.
* DTO-like structures.
* Frontend API integrations.
* UI fields backed by API data.

Treat the data flow as one contract:

```text
Database
   ↓
Model / Domain
   ↓
Request / Action
   ↓
API Resource
   ↓
Frontend API Layer
   ↓
UI
```

Do not silently rename, reinterpret, drop, coerce, or duplicate fields between layers without verifying the contract.

When changing one layer, inspect affected upstream and downstream layers.

# Request Handling

Before modifying:

* Laravel request classes.
* Validation rules.
* Authorization within requests.
* Actions that consume request data.
* Input normalization.
* Persistence of request-derived data.

Read and follow:

```text
docs/Request_Handling.md
```

That document is the source of truth for:

* Request contracts.
* Typed accessors.
* Validation syntax.
* Authorization.
* Input normalization.
* Persistence boundaries.

For PHPStan-sensitive request access or nested input, also use:

```text
.agents/skills/typesafe-laravel-php/SKILL.md
```

Avoid allowing `mixed` values to propagate unnecessarily through application code.

# Testing and Quality Gates

Every new feature or behavioral change must have corresponding tests.

Tests should verify the behavior introduced or changed by the task, including tenant isolation where applicable.

Do not modify unrelated tests merely to obtain a green test suite.

When backend files change, run:

```bash
cd backend && composer stan
cd backend && composer test
```

Both must pass before formatting.

After both pass, run:

```bash
cd backend && composer pint
```

Expected backend flow:

```text
Implementation
     ↓
PHPStan
     ↓
Tests
     ↓
Pint
```

If PHPStan or tests fail because of the current task, fix those failures before finishing.

If unrelated pre-existing failures exist, report them instead of silently expanding the task scope.

For frontend changes, follow the validation and testing commands defined in `frontend/AGENTS.md` and the frontend application's package scripts.

# Database and Schema Inspection

Do not assume that an old migration or an Eloquent model accurately describes the currently active database schema.

Use Laravel's database inspection command for existing tables.

Example:

```bash
cd backend && php artisan db:table users
```

Use non-interactive Tinker for targeted runtime checks.

Example:

```bash
cd backend && php artisan tinker --execute='use Modules\Core\Models\User\User; var_dump(User::query()->first());'
```

Keep runtime checks deterministic and narrowly scoped to the current task.

# Migrations

Migrations are forward-only.

Do not implement:

```php
public function down(): void
{
    // ...
}
```

A deployed schema change should be corrected with a later compensating migration.

Example:

```text
001_create_products
        ↓
002_add_product_status
        ↓
003_replace_legacy_product_field
```

Do not rely on rolling back deployed migrations as the normal mechanism for schema evolution.

# Laravel Project Conventions

## 1. General Coding Standards

* Use PHPStan-safe PHPDoc for methods and properties that interact with models, collections, arrays, or other types PHPStan cannot infer reliably.
* Document array shapes when passing structured arrays.
* Define explicit return types for all methods, including nullable return types.
* Mark classes as `final` by default unless inheritance is intentionally supported.
* Avoid unnecessary inline comments.
* Prefer clear class, method, and variable names that communicate intent directly.

Example:

```php
final class FindUserAction
{
    public function execute(int $userId): ?User
    {
        return User::query()->find($userId);
    }
}
```

For structured arrays:

```php
/**
 * @param array{
 *     name: string,
 *     email: string
 * } $attributes
 */
```

## 2. Eloquent and Querying

Use `Model::query()` as the entry point for Eloquent operations.

Prefer:

```php
User::query()->find($id);
User::query()->create($attributes);
User::query()->where('email', $email)->first();
```

rather than:

```php
User::find($id);
User::create($attributes);
```

Prefer Eloquent over `DB::` and Query Builder for ordinary domain queries.

Use lower-level database APIs only when the query genuinely requires them.

Whenever an operation performs more than one related database write that must succeed atomically, wrap the operation in:

```php
DB::transaction(function (): void {
    // related writes
});
```

Tenant context must remain correct throughout the transaction.

## 3. Date Handling

Use:

```php
Carbon\CarbonImmutable
```

for date and time operations.

Avoid introducing mutable `Carbon\Carbon` state when immutable date handling is sufficient.

## 4. Request Handling and Validation

Before changing any request class or any action that consumes request input, read:

```text
docs/Request_Handling.md
```

That document defines project conventions for:

* Validation.
* Authorization.
* Normalization.
* Typed access.
* Nested request input.
* Nullable values.
* Persistence boundaries.

Do not invent an alternative request-handling convention locally.

## 5. String Interpolation

Avoid unnecessary curly braces in PHP string interpolation.

Prefer:

```php
"Hello $name"
```

instead of:

```php
"Hello {$name}"
```

Use curly braces only where they are required for unambiguous or complex interpolation.

# Avoid Unnecessary Abstractions

Do not create helper methods or utility functions merely to wrap:

* A single property access.
* One translation lookup.
* A single ternary.
* A one-use branch.
* A trivial formatting expression.

Create an abstraction when it:

* Is reused.
* Encapsulates meaningful domain behavior.
* Removes substantial complexity.
* Establishes a useful architectural boundary.

# Laravel Documentation

When uncertain about Laravel-specific behavior, do not rely only on assumptions or framework memory.

Check the relevant project documentation under:

```text
docs/laravel/
```

before deciding on an implementation.

Prefer repository-specific documentation and conventions over generic framework assumptions when the two differ.

# General Task Workflow

For a backend task:

```text
1. Read root AGENTS.md.
2. Read backend/AGENTS.md if present.
3. Determine central vs tenant-owned data.
4. Identify affected tenant isolation boundaries.
5. Read DATA_CONTRACT_INTEGRITY_RULE.md.
6. Read Request_Handling.md when request data is involved.
7. Read module documentation/skills when module structure is involved.
8. Inspect existing implementation and active database schema.
9. Implement the smallest coherent change.
10. Add or update tests.
11. Run composer stan.
12. Run composer test.
13. Run composer pint after both pass.
```

For a frontend task:

```text
1. Read root AGENTS.md.
2. Read frontend/AGENTS.md.
3. Identify the affected API/data contract.
4. Read DATA_CONTRACT_INTEGRITY_RULE.md when API-backed data is involved.
5. Read translate.md when i18n is involved.
6. Implement through the established frontend architecture.
7. Add or update relevant tests.
8. Run the frontend checks defined by frontend/AGENTS.md.
```

For a full-stack task:

```text
1. Read root AGENTS.md.
2. Read both frontend/AGENTS.md and backend/AGENTS.md.
3. Classify tenancy and data ownership.
4. Establish the API/data contract before implementation.
5. Update backend and frontend consistently.
6. Verify request and response compatibility.
7. Test tenant isolation and functional behavior.
8. Run the required quality checks for both applications.
```
