# Request Handling Guide

This file is the source of truth for request handling in the project. Read it before changing any class under `server/modules/*/Http/Requests` or any action that consumes request data. If the flow is API-facing, also follow `docs/DATA_CONTRACT_INTEGRITY_RULE.md`.

## Core Rules

- Use a dedicated `FormRequest` for every endpoint that accepts input.
- Every request class must extend Laravel's `FormRequest`.
- Keep request classes `final` unless a clear exception is needed.
- Put authorization in `authorize()` and use policies or `Gate`.
- If a request depends on a route-bound model, resolve it explicitly and fail fast when the binding is missing.
- Define `rules()` as arrays.
- Keep schema rules (types, requiredness, nullability, limits, enums) in `rules()` so OpenAPI generation can evaluate them without a tenant or route binding. Add tenant-scoped database rules and checks that depend on bound models through `$validator->addRules()` in `withValidator()`. Resolve and require the organization and bound models in that hook; never relax tenant validation for documentation generation.
- Use `Rule` objects or custom rule classes for non-trivial validation.
- Do not use pipe-delimited rule strings such as `required|string|max:255`.
- Prefer `Rule::exists()`, `Rule::unique()`, `Rule::enum()`, and `Rule::in()` over string equivalents.
- Do not use `input()` or `get()` on `Request` or `FormRequest`.
- Use typed accessors instead: `string()->value()`, `integer()`, `boolean()`, `float()`, `array()`, `date()`, and `enum()`.
- Use `validated()` in actions and persistence code. Do not build write payloads from raw request input.
- Actions must type-hint app-specific request classes, not `Illuminate\Http\Request`.
- Use `prepareForValidation()` only for minimal boundary normalization or sanitization.
- Keep business logic out of the request layer.
- Use custom `messages()` only when they improve UX or are required by the API contract.
- For array payloads, validate nested keys explicitly and block forbidden fields with `missing` or `prohibited` rules.
- Keep requests lean and validate only the fields the endpoint actually accepts.
- Request classes live under `backend/modules/{Module}/Http/Requests` and should match the action submodule where practical.
- Actions must import request classes from their own matching request directory. If multiple action domains need an empty/authenticated request, define it in each matching request directory instead of importing across domains.

## Enforced By Tests And PHPStan

- PHPStan rules: `NoRequestInputGetRule`, `NoPipeStringInRequestRulesRule`, `NoIlluminateHttpRequestImportRule`
- Tests: `server/tests/Architecture/AppArchitectureTest.php`, `server/tests/Architecture/NoRequestInputGetRuleTest.php`, `server/tests/Architecture/NoPipeStringInRequestRulesRuleTest.php`, `server/tests/Architecture/NoIlluminateHttpRequestImportRuleTest.php`
- If you change request conventions, update the matching rule test or fixture.
