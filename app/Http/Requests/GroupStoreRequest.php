<?php

declare(strict_types=1);

namespace App\Http\Requests;

class GroupStoreRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tg_gid' => ['required', 'integer', 'not_in:0'],
            'tg_oid' => ['required', 'integer', 'min:1'],
            'tg_g_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tg_o_nickname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_open' => ['sometimes', 'boolean'],
            'base_currency' => ['sometimes', 'string', 'size:1'],
            'quote_currency' => ['sometimes', 'string', 'size:1'],
            'exchange_rate' => ['sometimes', 'numeric', 'gt:0'],
            'fee_rate' => ['sometimes', 'numeric', 'between:0,100'],
            'period_point' => ['sometimes', 'integer', 'between:0,23'],
            'period_duration' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
