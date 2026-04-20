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
            'regular' => [
                'required',
                'string',
                'max:512',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (!is_string($value) || @preg_match($value, '') === false) {
                        $fail('regular 必须是可用的 PHP 正则表达式（PCRE）。');
                    }
                },
            ],
            'api' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'data_map' => [
                'sometimes',
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if (!is_string($value) || json_decode($value, true) === null && json_last_error() !== JSON_ERROR_NONE) {
                        $fail('data_map 必须是合法 JSON 字符串。');
                    }
                },
            ],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
