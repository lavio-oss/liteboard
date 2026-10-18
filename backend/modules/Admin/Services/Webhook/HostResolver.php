<?php

namespace Modules\Admin\Services\Webhook;

interface HostResolver
{
    /** @return list<string> every A and AAAA address of the host */
    public function resolve(string $host): array;
}
