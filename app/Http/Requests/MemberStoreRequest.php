<?php

declare(strict_types=1);

namespace App\Http\Requests;

class MemberStoreRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tg_gid' => ['required', 'integer', 'min:1'],
            'tg_uid' => ['required', 'integer', 'min:1'],
            'tg_g_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tg_nickname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'role' => ['required', 'in:operator,consumer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
