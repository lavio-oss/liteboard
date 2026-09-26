<?php

namespace Modules\Core\Models\Auth;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

final class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasUuids;
}
