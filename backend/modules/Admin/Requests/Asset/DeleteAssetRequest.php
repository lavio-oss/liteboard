<?php

namespace Modules\Admin\Requests\Asset;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Admin\Models\Asset\Asset;
use Modules\Core\Models\User\User;

final class DeleteAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $asset = $this->route('asset');
        $user = $this->user();

        return $asset instanceof Asset
            && $user instanceof User
            && Gate::forUser($user)->allows('delete', $asset);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [];
    }
}
