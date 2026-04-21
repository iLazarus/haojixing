<?php

declare(strict_types=1);

namespace App\Http\Requests;

class MemberUpdateRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tg_g_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tg_nickname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'role' => ['sometimes', 'in:operator,consumer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
