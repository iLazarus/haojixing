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
            'tg_gid' => ['sometimes', 'integer', 'min:1'],
            'tg_uid' => ['sometimes', 'integer', 'min:1'],
            'tg_belong_uid' => ['sometimes', 'integer', 'min:1'],
            'tg_msg_id' => ['sometimes', 'integer', 'min:1'],
            'amount' => ['sometimes', 'integer'],
            'is_delete' => ['sometimes', 'boolean'],
        ];
    }
}
