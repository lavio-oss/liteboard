<?php

namespace Modules\Admin\Enums\Device;

enum DeviceApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
