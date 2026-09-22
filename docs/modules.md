# Backend module structure

LiteBoard keeps domain code under `backend/modules` and reserves `backend/app`
for host-level framework integration.

Each submodule uses the following concern layout:

```text
Module/
├── Actions/<submodule>/
│   ├── Http/             # invokable ADR actions for API endpoints
│   └── ...               # domain/use-case actions
├── Enums/<submodule>/
├── Models/<submodule>/
├── Policies/<submodule>/
├── Requests/<submodule>/
├── Resources/<submodule>/ # API representation
├── Services/<submodule>/
└── Routes/<submodule-kebab>.php
```

`Core/Shared/Responders` is the cross-cutting transport-level response
boundary used by the module Actions.

The backend follows Action-Domain-Responder (ADR): routes point to one
invokable HTTP Action, the Action coordinates a domain Action or model, and a
Responder converts the result into the HTTP response. Controllers are not used
for module endpoints.

`Core/Shared` is the only cross-cutting exception. New domain modules must
keep tenant-owned data scoped through the active tenant context and must not
move business logic into `backend/app`.
