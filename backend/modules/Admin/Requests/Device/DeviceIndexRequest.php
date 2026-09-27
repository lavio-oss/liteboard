<?php

namespace Modules\Admin\Requests\Device;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Admin\Enums\Asset\AssetType;
use Modules\Admin\Enums\Device\DeviceConnectionState;
use Modules\Admin\Enums\Device\DeviceStatus;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\User\User;

final class DeviceIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $project instanceof Project
            && $user instanceof User
            && Gate::forUser($user)->allows('viewAny', [Device::class, $project]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(DeviceStatus::class)],
            'connection_state' => ['sometimes', Rule::enum(DeviceConnectionState::class)],
            'equipment_asset_id' => ['sometimes', 'bail', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            throw new LogicException('The project route binding is required.');
        }

        $validator->addRules([
            'equipment_asset_id' => [
                Rule::exists('assets', 'id')->where(
                    static function (Builder $query) use ($project): void {
                        $query
                            ->where('project_id', $project->getKey())
                            ->where('asset_type', AssetType::Equipment->value);
                    },
                ),
            ],
        ]);
    }
}
