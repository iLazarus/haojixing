<?php

declare(strict_types=1);

namespace App\Http\Requests;

class RuleStoreRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'remark' => ['sometimes', 'string', 'max:255'],
            'regular' => ['required', 'string', 'max:512'],
            'api' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'data_map' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
