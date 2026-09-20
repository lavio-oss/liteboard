<?php

namespace Modules\Masterdata\Actions\Factory;

use Modules\Masterdata\Models\Factory\Factory;

final class UpdateFactoryAction
{
    /**
     * @param  array{name?: string, code?: string, location?: string|null, status?: string}  $attributes
     */
    public function execute(Factory $factory, array $attributes): Factory
    {
        $factory->fill($attributes);
        $factory->save();

        return $factory->refresh();
    }
}
