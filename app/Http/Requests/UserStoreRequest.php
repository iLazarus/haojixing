<?php

declare(strict_types=1);

namespace App\Http\Requests;

class UserStoreRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tg_uid' => ['required', 'integer', 'min:1'],
            'tg_username' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tg_nickname' => ['sometimes', 'nullable', 'string', 'max:128'],
        ];
    }
}
