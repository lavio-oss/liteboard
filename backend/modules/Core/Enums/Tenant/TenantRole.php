<?php

namespace Modules\Core\Enums\Tenant;

enum TenantRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
}
