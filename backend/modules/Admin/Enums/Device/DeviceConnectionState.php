<?php

namespace Modules\Admin\Enums\Device;

enum DeviceConnectionState: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Unstable = 'unstable';
}
