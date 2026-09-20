# Data Contract & Integrity Rule

## Spirit (the 5 non-negotiables)

- **The contract is the law:** TypeScript types, DTOs, and PHPDocs define truth. Code must match the contract—no “just in case” defensive checks.
- **Single Source of Truth per field:** every field has exactly one authoritative source. If it’s missing/invalid, **fail fast** with a clear error.
- **No fallbacks or compat hacks:** no multi-source probing (`??`, `||`), no legacy aliases, no guessing helpers, no shadow copies or hidden reconciliation.
- **Boundary-only validation/normalization:** parsing, coercion, trimming, and shape-fixing happen only at boundaries (ingestion/request/DTO/validator/migration), not in UI/business/shared logic.
- **Integrity violations must be loud and stopping:** no catch-and-ignore, no defaults that mask bad data, and no partial updates that can create inconsistent state—if atomicity isn’t guaranteed, the operation must fail.

---

## Rules

### 1) Single Source of Truth (SST)
Every field must have exactly one authoritative source.

Forbidden: multi-source fallback (e.g., `a ?? b`) for the same field.

If the authoritative source is missing/invalid → fail fast with a clear error.

### 2) No legacy / compat fields
Forbidden: duplicate field names for the same meaning (e.g., `lotSize` and `lot_size`).

Forbidden: compat fallbacks like `product?.lot_size ?? product?.lotSize`.

When renaming a field: migrate data → update code → remove old field/alias immediately.

### 3) Forbidden: multi-source “normalization helpers”
Forbidden: helpers that probe multiple objects in sequence (e.g., `normalizeProductId(lot, lotPlan, product)`).

If the source is unclear → fail fast, do not guess.

### 4) Maximum explicitness (no shadow flows)
No hidden reconciliation logic.

Do not keep “shadow copies” of the same field across places and reconcile later.

No backward-compat hacks in core/shared logic.

### 5) Nullability discipline (TS types / PHPDocs are law)
Interfaces/DTOs/PHPDocs define truth.

Forbidden: defensive null/emptiness checks on fields declared non-null (e.g., `?.`, `?->`, `isset/empty`, `== null`) “just in case”.

If reality contradicts the contract: fix the contract + migrate/fix data, not defensive code.

### 6) Ban unnecessary `??` and `||` when the contract is clear
If a value is non-nullable / required by type or PHPDoc, you must not write:

- `x ?? default`
- `x || default`
- `x || fallbackText`

Defaults must not be used to mask bad/missing data.

If the field is required and missing → throw / error (fail fast), don’t invent fallback values.

### 7) Ban “trim/empty probing” on data fields
Absolutely forbidden: using `trim(value)` (or equivalent) to decide emptiness/validity for data fields.

No “normalize by trimming” inside business/shared UI logic.

If whitespace normalization is needed → do it once at ingest/validation/migration, explicitly.

### 8) UI strings: no i18n fallback hacks
Forbidden: `t('key') || 'fallback text'`.

Missing translation keys must be treated as errors (don’t hide them).

### 9) TypeScript: any is banned
Absolute ban on `any`.

Use `unknown` + explicit validation/parsing, or define correct types.

### 10) Don’t create “compact/minimal types” unless necessary
Forbidden: creating “minimal/compact” types (or remapped DTOs) solely to return/read fewer fields.

If you only need a few fields, using the larger type/model is fine—return/pass the original object unchanged.

Example: If `Product` has `id, name, unit_id, created_at, updated_at`, and FE only needs `id` and `name`, do **not** return:
- `product_id = product->id`
- `product_name = product->name`

Return `product` as-is and let FE read `product.id` and `product.name`.

### 11) No BE→FE key remapping glue
Forbidden: per-call mapping only because keys differ (e.g., `productId = data.product_id`).

Fix via contract/schema alignment + migration, not ad-hoc mapping at call sites.

### 12) No pointless intermediate variables
Forbidden: introducing pass-through variables that add no reuse/clarity/guarding.

Prefer `fn(obj.id)` over `const id = obj.id; fn(id)` unless you reuse it, rename for meaning, or guard/validate it.

---

## Additional bans to keep data code clean (requested: D, E, H, I)

### 13) Ban unnecessary parsing/coercion (D)
Forbidden: ad-hoc parsing/coercion when the type is already correct (e.g., `parseInt(id)`, `String(id)`, `Number(x)`, `+x`, date parsing), “just in case”.

Conversions/parsing are allowed only at boundaries (DTO construction, request parsing, ingestion), and must be explicit and centralized.

### 14) Keep validation/normalization out of UI/business logic (E)
Forbidden: components/services “fixing” payloads (normalizing shapes, patching missing fields, trimming, coercing).

Validation/normalization belongs to boundary layers (parser/DTO/validator/migration), not inside core/shared logic.

### 15) Ban catch-and-ignore on data integrity (H)
Forbidden: swallowing errors around required data access:

```ts
try { ... } catch { return null }
```

### 16) Prefer framework primitives over custom helpers
Do not introduce custom helper functions for behavior already provided by the framework.

Use built-in framework mechanisms first (for example, Laravel `Validator` for data validation) instead of inventing parallel validation helpers.

Only add custom helpers when there is a clear gap in framework capabilities and the helper is reused meaningfully.

### 17) TypeScript object validation must use a schema validator
Validate unknown TypeScript objects with Zod, or Typia when it is already available.

Manual field parsing and validation helpers such as `parseString`, `readString`, `readObject`, or equivalents are forbidden.

### 18) Prefer direct contract usage
Render, pass, and compare contract fields directly unless an explicit requirement demands transformation.

Forbidden:

- Presentation-only transformations such as `slice`, `trim`, casing, or manual date formatting without an explicit requirement.
- Helpers that only wrap property access, a fallback, a ternary, or one built-in operation.
- Extracting a function used once when the inline expression is already clear.
- Defensive optional chaining or fallback values that contradict the declared contract.

```ts
// Forbidden
displayValue(user.name)
user.profile?.birthday?.slice(0, 10)

// Required when the contract declares these fields as present
user.name
user.profile.birthday
```

Create an abstraction only when it encodes domain behavior, is meaningfully reused, removes substantial complexity, or validates a system boundary. If the contract shape is wrong, fix the contract instead of patching values at render sites.