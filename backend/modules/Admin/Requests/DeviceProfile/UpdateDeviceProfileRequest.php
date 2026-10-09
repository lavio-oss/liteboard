<?php

namespace Modules\Admin\Requests\DeviceProfile;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\User\User;

final class UpdateDeviceProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $profile = $this->route('deviceProfile');
        $user = $this->user();

        return $profile instanceof DeviceProfile
            && $user instanceof User
            && Gate::forUser($user)->allows('update', $profile);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'min:1', 'max:64'],
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'tenant_id' => ['prohibited'],
            'project_id' => ['prohibited'],
        ];
    }

    /**
     * @return array{code?: string, name?: string, description?: string|null}
     */
    public function profileAttributes(): array
    {
        /** @var array{code?: string, name?: string, description?: string|null} $attributes */
        $attributes = $this->validated();

        return $attributes;
    }

    public function withValidator(Validator $validator): void
    {
        $project = $this->route('project');
        $profile = $this->route('deviceProfile');

        if (! $project instanceof Project || ! $profile instanceof DeviceProfile) {
            throw new LogicException('The project and device profile route bindings are required.');
        }

        $validator->addRules([
            'code' => [
                Rule::unique('device_profiles', 'code')
                    ->where(static function (Builder $query) use ($project): void {
                        $query->where('project_id', $project->getKey());
                    })
                    ->ignore($profile->getKey()),
            ],
        ]);
    }
}
