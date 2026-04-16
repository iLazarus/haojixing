<?php

declare(strict_types=1);

namespace App\Http\Requests;

class GroupRuleStoreRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'app_rule_id' => ['required', 'integer', 'min:1'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'stop_on_match' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
