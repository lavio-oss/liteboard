<?php

namespace Modules\Admin\Services\DeviceProvisioning;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class DeviceProvisioningException extends HttpException
{
    public function __construct(public readonly int $status, string $errorCode, public readonly ?int $retryAfter = null)
    {
        parent::__construct($status, $errorCode);
    }
}
