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
            'is_delete' => ['sometimes', 'boolean'],
        ];
    }
}
