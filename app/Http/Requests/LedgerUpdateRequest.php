<?php

declare(strict_types=1);

namespace App\Http\Requests;

class LedgerUpdateRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['sometimes', 'integer'],
            'currency_type' => ['sometimes', 'string', 'in:R,U'],
            'tg_nickname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tg_belong_nickname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tg_g_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_delete' => ['sometimes', 'boolean'],
        ];
    }
}
