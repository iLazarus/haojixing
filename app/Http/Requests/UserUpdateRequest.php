<?php

declare(strict_types=1);

namespace App\Http\Requests;

class UserUpdateRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tg_username' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tg_nickname' => ['sometimes', 'nullable', 'string', 'max:128'],
        ];
    }
}
