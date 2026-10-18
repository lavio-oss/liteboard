<?php

namespace Modules\Admin\Requests\Alert;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Admin\Models\Alert\Alert;
use Modules\Core\Models\User\User;

final class AcknowledgeAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        $alert = $this->route('alert');
        $user = $this->user();

        return $alert instanceof Alert
            && $user instanceof User
            && Gate::forUser($user)->allows('acknowledge', $alert);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'cleared_at' => ['prohibited'],
            'clear_reason' => ['prohibited'],
        ];
    }
}
