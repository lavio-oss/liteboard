<?php

namespace Modules\Admin\Requests\Device;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Admin\Enums\Asset\AssetType;
use Modules\Admin\Enums\Device\DeviceStatus;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Device\DeviceCredential;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\User\User;

final class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $project instanceof Project
            && $user instanceof User
            && Gate::forUser($user)->allows('create', [Device::class, $project]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'code' => ['required', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'equipment_asset_id' => ['sometimes', 'nullable', 'bail', 'uuid'],
            'status' => ['sometimes', Rule::enum(DeviceStatus::class)],
            'access_token' => [
                'sometimes',
                'nullable',
                'string',
                'min:16',
                'max:64',
                'regex:/^[A-Za-z0-9_-]+$/',
            ],
            'tenant_id' => ['prohibited'],
            'project_id' => ['prohibited'],
        ];
    }

    /**
     * @return array{
     *     attributes: array{
     *         name: string,
     *         code: string,
     *         description?: string|null,
     *         equipment_asset_id?: string|null,
     *         status?: string
     *     },
     *     access_token: string|null
     * }
     */
    public function deviceInput(): array
    {
        /** @var array{
         *     name: string,
         *     code: string,
         *     description?: string|null,
         *     equipment_asset_id?: string|null,
         *     status?: string,
         *     access_token?: string|null
         * } $validated
         */
        $validated = $this->validated();
        $accessToken = $validated['access_token'] ?? null;
        unset($validated['access_token']);

        /** @var array{
         *     name: string,
         *     code: string,
         *     description?: string|null,
         *     equipment_asset_id?: string|null,
         *     status?: string
         * } $attributes
         */
        $attributes = $validated;

        return [
            'attributes' => $attributes,
            'access_token' => $accessToken,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            throw new LogicException('The project route binding is required.');
        }

        $validator->addRules([
            'code' => [
                Rule::unique('devices', 'code')->where(
                    static function (Builder $query) use ($project): void {
                        $query->where('project_id', $project->getKey());
                    },
                ),
            ],
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

        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('access_token') || ! $this->filled('access_token')) {
                return;
            }

            $tokenHash = hash('sha256', $this->string('access_token')->value());

            if (DeviceCredential::query()->where('token_hash', $tokenHash)->exists()) {
                $validator->errors()->add('access_token', 'The access token has already been taken.');
            }
        });
    }
}
