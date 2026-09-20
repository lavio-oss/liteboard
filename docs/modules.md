# Backend module structure

LiteBoard keeps domain code under `backend/modules` and reserves `backend/app`
for host-level framework integration.

Each submodule uses the following concern layout:

```text
Module/
├── Actions/<submodule>/
├── Controllers/<submodule>/
├── Enums/<submodule>/
├── Models/<submodule>/
├── Policies/<submodule>/
├── Requests/<submodule>/
├── Resources/<submodule>/
├── Services/<submodule>/
└── Routes/<submodule-kebab>.php
```

`Core/Shared` is the only cross-cutting exception. New domain modules must
keep tenant-owned data scoped through the active tenant context and must not
move business logic into `backend/app`.
