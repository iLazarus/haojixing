<?php

declare(strict_types=1);

namespace App\Http\Requests;

class LedgerStoreRequest extends ApiFormRequest
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
            'tg_nickname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tg_belong_uid' => ['required', 'integer', 'min:1'],
            'tg_belong_nickname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tg_msg_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'integer'],
            'currency_type' => ['sometimes', 'string', 'in:R,U'],
            'tg_g_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_delete' => ['sometimes', 'boolean'],
        ];
    }
}
