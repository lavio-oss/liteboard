<?php

namespace Modules\Masterdata\Actions\Factory;

use Modules\Masterdata\Models\Factory\Factory;
use Modules\Masterdata\Models\Project\Project;

final class CreateFactoryAction
{
    /**
     * @param  array{name: string, code: string, location?: string|null, status?: string}  $attributes
     */
    public function execute(Project $project, array $attributes): Factory
    {
        $factory = new Factory;
        $factory->project()->associate($project);
        $factory->fill($attributes);
        $factory->save();

        return $factory->refresh();
    }
}
