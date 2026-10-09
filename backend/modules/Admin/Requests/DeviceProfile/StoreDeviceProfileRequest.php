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

final class StoreDeviceProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $project instanceof Project
            && $user instanceof User
            && Gate::forUser($user)->allows('create', [DeviceProfile::class, $project]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:1', 'max:64'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'tenant_id' => ['prohibited'],
            'project_id' => ['prohibited'],
        ];
    }

    /**
     * @return array{code: string, name: string, description?: string|null}
     */
    public function profileAttributes(): array
    {
        /** @var array{code: string, name: string, description?: string|null} $attributes */
        $attributes = $this->validated();

        return $attributes;
    }

    public function withValidator(Validator $validator): void
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            throw new LogicException('The project route binding is required.');
        }

        $validator->addRules([
            'code' => [
                Rule::unique('device_profiles', 'code')->where(
                    static function (Builder $query) use ($project): void {
                        $query->where('project_id', $project->getKey());
                    },
                ),
            ],
        ]);
    }
}
