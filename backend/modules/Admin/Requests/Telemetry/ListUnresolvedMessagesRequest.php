<?php

namespace Modules\Admin\Requests\Telemetry;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Models\User\User;

final class ListUnresolvedMessagesRequest extends FormRequest
{
    // Central data carrying unverified topic IDs, so only platform admins may read it.
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isPlatformAdmin();
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
