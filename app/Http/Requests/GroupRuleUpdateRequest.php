<?php

declare(strict_types=1);

namespace App\Http\Requests;

class GroupRuleUpdateRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'priority' => ['sometimes', 'integer', 'min:0'],
            'stop_on_match' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
