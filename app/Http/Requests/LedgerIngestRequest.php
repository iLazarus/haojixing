<?php

declare(strict_types=1);

namespace App\Http\Requests;

class LedgerIngestRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 这里定义的是接口输入校验，不包含数据库存在性校验。
     */
    public function rules(): array
    {
        return [
            'tg_gid' => ['required', 'integer', 'min:1'],
            'tg_uid' => ['required', 'integer', 'min:1'],
            'tg_nickname' => ['nullable', 'string', 'max:255'],
            'tg_belong_uid' => ['required', 'integer', 'min:1'],
            'tg_belong_nickname' => ['nullable', 'string', 'max:255'],
            'tg_msg_id' => ['required', 'integer', 'min:1'],
            'tg_g_name' => ['nullable', 'string', 'max:255'],
            'currency_type' => ['nullable', 'string', 'in:R,U'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],

            // 用户输入 0-100，支持小数，含边界
            'fee_rate' => ['nullable', 'numeric', 'between:0,100'],

            // 用户输入单位为元，可带小数
            'amount_yuan' => ['required', 'numeric'],
        ];
    }

    public function messages(): array
    {
        return [
            'fee_rate.between' => 'fee_rate 仅允许 0-100，支持小数。',
            'exchange_rate.gt' => 'exchange_rate 必须大于 0。',
        ];
    }
}
