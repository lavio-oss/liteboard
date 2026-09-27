<?php

namespace Modules\Admin\Enums\Device;

enum DeviceCredentialStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
