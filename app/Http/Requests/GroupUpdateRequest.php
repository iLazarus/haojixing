<?php

declare(strict_types=1);

namespace App\Http\Requests;

class GroupUpdateRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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
