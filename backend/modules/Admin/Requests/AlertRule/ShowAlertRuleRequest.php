<?php

namespace Modules\Admin\Requests\AlertRule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Core\Models\User\User;

final class ShowAlertRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rule = $this->route('alertRule');
        $user = $this->user();

        return $rule instanceof AlertRule
            && $user instanceof User
            && Gate::forUser($user)->allows('view', $rule);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }
}
