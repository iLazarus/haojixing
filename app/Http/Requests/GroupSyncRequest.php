<?php

declare(strict_types=1);

namespace App\Http\Requests;

class GroupSyncRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'trigger_tg_uid' => ['sometimes', 'integer', 'min:1'],
            'trigger_nickname' => ['sometimes', 'nullable', 'string', 'max:128'],
            'fallback_group_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
