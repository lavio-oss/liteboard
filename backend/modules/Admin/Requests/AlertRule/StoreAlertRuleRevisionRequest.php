<?php

namespace Modules\Admin\Requests\AlertRule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\AlertRule\AlertRuleRevision;
use Modules\Core\Models\User\User;

/**
 * @phpstan-import-type RuleDefinition from AlertRuleRevision
 */
final class StoreAlertRuleRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rule = $this->route('alertRule');
        $user = $this->user();

        return $rule instanceof AlertRule
            && $user instanceof User
            && Gate::forUser($user)->allows('update', $rule);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...AlertRuleDefinitionRules::rules(),
            'revision' => ['prohibited'],
            'project_id' => ['prohibited'],
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
        $rule = $this->route('alertRule');

        if (! $rule instanceof AlertRule) {
            throw new LogicException('The alert rule route binding is required.');
        }

        $validator->after(static function (Validator $validator) use ($rule): void {
            AlertRuleDefinitionRules::validateSemantics($validator, $rule->deviceProfile()->firstOrFail());
        });
    }
}
