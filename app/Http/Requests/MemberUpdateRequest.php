<?php

declare(strict_types=1);

namespace App\Http\Requests;

class MemberUpdateRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['sometimes', 'in:operator,consumer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
