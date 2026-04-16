<?php

declare(strict_types=1);

namespace App\Http\Requests;

class RuleMatchRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tg_msg_id' => ['required', 'integer', 'min:1'],
            'message' => ['required', 'string', 'min:1', 'max:5000'],
            'execute_api' => ['sometimes', 'boolean'],
            'context' => ['sometimes', 'array'],
            'context.*' => ['nullable'],
        ];
    }
}
