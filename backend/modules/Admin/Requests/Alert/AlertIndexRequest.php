<?php

namespace Modules\Admin\Requests\Alert;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Admin\Models\Alert\Alert;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\User\User;

final class AlertIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $project instanceof Project
            && $user instanceof User
            && Gate::forUser($user)->allows('viewAny', [Alert::class, $project]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'state' => ['sometimes', 'string', 'in:active,cleared'],
            'acknowledged' => ['sometimes', 'boolean'],
            'device_id' => ['sometimes', 'uuid'],
            'alert_rule_id' => ['sometimes', 'uuid'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:512'],
        ];
    }

    /**
     * @return array{state: string|null, acknowledged: bool|null, device_id: string|null, alert_rule_id: string|null, limit: int}
     */
    public function filters(): array
    {
        return [
            'state' => $this->has('state') ? $this->string('state')->value() : null,
            'acknowledged' => $this->has('acknowledged') ? $this->boolean('acknowledged') : null,
            'device_id' => $this->has('device_id') ? $this->string('device_id')->value() : null,
            'alert_rule_id' => $this->has('alert_rule_id') ? $this->string('alert_rule_id')->value() : null,
            'limit' => $this->integer('limit', 50),
        ];
    }
}
