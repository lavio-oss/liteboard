<?php

namespace Modules\Admin\Requests\AlertRule;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\AlertRule\AlertRuleRevision;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\User\User;

/**
 * @phpstan-import-type RuleDefinition from AlertRuleRevision
 */
final class StoreAlertRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $project instanceof Project
            && $user instanceof User
            && Gate::forUser($user)->allows('create', [AlertRule::class, $project]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:1', 'max:64'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'device_profile_id' => ['required', 'uuid'],
            ...AlertRuleDefinitionRules::rules(),
            'tenant_id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    /** @return array{code: string, name: string, device_profile_id: string} */
    public function ruleAttributes(): array
    {
        return [
            'code' => $this->string('code')->value(),
            'name' => $this->string('name')->value(),
            'device_profile_id' => $this->string('device_profile_id')->value(),
        ];
    }

    /** @return RuleDefinition */
    public function definition(): array
    {
        /** @var array{definition: array{field: string, trigger: array{operator: string, value: int|float|string, duration_seconds: int|string}, clear: array{operator: string, value: int|float|string, duration_seconds: int|string}, max_sample_gap_seconds: int|string, severity: string}} $validated */
        $validated = $this->validated();

        return AlertRuleDefinitionRules::normalize($validated['definition']);
    }

    public function withValidator(Validator $validator): void
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            throw new LogicException('The project route binding is required.');
        }

        $validator->addRules([
            'code' => [
                Rule::unique('alert_rules', 'code')->where(
                    static function (Builder $query) use ($project): void {
                        $query->where('project_id', $project->getKey());
                    },
                ),
            ],
            'device_profile_id' => [
                Rule::exists('device_profiles', 'id')->where(
                    static function (Builder $query) use ($project): void {
                        $query->where('project_id', $project->getKey());
                    },
                ),
            ],
        ]);

        $validator->after(static function (Validator $validator) use ($project): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $profile = DeviceProfile::query()
                ->where('project_id', $project->getKey())
                ->findOrFail($validator->getData()['device_profile_id']);
            AlertRuleDefinitionRules::validateSemantics($validator, $profile);
        });
    }
}
