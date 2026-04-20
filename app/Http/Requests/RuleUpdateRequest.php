<?php

declare(strict_types=1);

namespace App\Http\Requests;

class RuleUpdateRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $merged = [];

        foreach (['is_active', 'is_default'] as $key) {
            if (!$this->has($key)) {
                continue;
            }

            $normalized = $this->normalizeBoolish($this->input($key));
            if ($normalized !== null) {
                $merged[$key] = $normalized;
            }
        }

        if ($this->has('regular')) {
            $regular = (string) $this->input('regular', '');
            $trimmed = trim($regular);
            if ($trimmed !== '' && !$this->isLikelyRegex($trimmed)) {
                // 支持直接输入纯文本，自动转换为“整句精确匹配”正则。
                $merged['regular'] = '/^' . preg_quote($trimmed, '/') . '$/u';
            }
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'remark' => ['sometimes', 'string', 'max:255'],
            'regular' => [
                'sometimes',
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
            'is_active' => ['sometimes', 'nullable', 'boolean'],
            'is_default' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    private function normalizeBoolish(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1 ? true : ($value === 0 ? false : null);
        }

        if (!is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    private function isLikelyRegex(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $delimiter = $value[0];
        if (ctype_alnum($delimiter) || $delimiter === '\\' || ctype_space($delimiter)) {
            return false;
        }

        $lastPos = strrpos($value, $delimiter);
        if ($lastPos === false || $lastPos <= 0) {
            return false;
        }

        $modifiers = substr($value, $lastPos + 1);

        return $modifiers === '' || preg_match('/^[imsxuADSUXJ]*$/', $modifiers) === 1;
    }
}
