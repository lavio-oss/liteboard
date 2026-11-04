<?php

namespace Modules\Core\Enums\Role;

enum RoleScope: string
{
    case Tenant = 'tenant';
    case Project = 'project';
}
