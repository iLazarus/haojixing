<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Models\AppLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LedgerIngestService
{
    /**
     * 幂等入账：同一 tg_gid + tg_msg_id 只会落一条记录。
     */
    public function ingest(array $payload): AppLedger
    {
        $this->assertBusinessRules($payload);

        return DB::transaction(function () use ($payload): AppLedger {
            $chinaDate = $this->chinaDate();

            $exists = AppLedger::query()
                ->where('tg_gid', (int) $payload['tg_gid'])
                ->where('tg_msg_id', (int) $payload['tg_msg_id'])
                ->first();

            if ($exists instanceof AppLedger) {
                return $exists;
            }

            $amountCent = $this->calculateFinalAmountCent(
                (string) $payload['amount_yuan'],
                (string) ($payload['exchange_rate'] ?? '1'),
                (string) ($payload['fee_rate'] ?? '0')
            );

            return AppLedger::query()->create([
                'tg_gid' => (int) $payload['tg_gid'],
                'tg_uid' => (int) $payload['tg_uid'],
                'tg_nickname' => (string) ($payload['tg_nickname'] ?? ''),
                'tg_belong_uid' => (int) $payload['tg_belong_uid'],
                'tg_belong_nickname' => (string) ($payload['tg_belong_nickname'] ?? ''),
                'tg_msg_id' => (int) $payload['tg_msg_id'],
                'is_delete' => false,
                'amount' => $amountCent,
                'currency_type' => $this->normalizeCurrencyType($payload['currency_type'] ?? null),
                'tg_g_name' => (string) ($payload['tg_g_name'] ?? ''),
                'created_at' => $chinaDate,
                'updated_at' => $chinaDate,
            ]);
        });
    }

    private function assertBusinessRules(array $payload): void
    {
        foreach (['tg_gid', 'tg_uid', 'tg_belong_uid', 'tg_msg_id'] as $key) {
            if (!isset($payload[$key]) || (int) $payload[$key] <= 0) {
                throw new InvalidArgumentException($key . ' 必须为正整数且不能为空');
            }
        }

        if (!isset($payload['amount_yuan'])) {
            throw new InvalidArgumentException('amount_yuan 不能为空');
        }

        $feeRate = (float) ($payload['fee_rate'] ?? 0);
        if ($feeRate < 0 || $feeRate > 100) {
            throw new InvalidArgumentException('fee_rate 范围必须是 0-100（百分比）');
        }

        $exchangeRate = (float) ($payload['exchange_rate'] ?? 1);
        if ($exchangeRate <= 0) {
            throw new InvalidArgumentException('exchange_rate 必须大于 0');
        }

        if (array_key_exists('currency_type', $payload)) {
            $this->normalizeCurrencyType($payload['currency_type']);
        }
    }

    /**
     * 用户输入金额单位为元，最终存储单位为分。
     * 计算流程：元 -> 分 -> 汇率换算 -> 扣费率。
     */
    private function calculateFinalAmountCent(string $amountYuan, string $exchangeRate, string $feeRate): int
    {
        $baseCent = (int) round(((float) $amountYuan) * 100, 0, PHP_ROUND_HALF_UP);
        $convertedCent = (int) round($baseCent * (float) $exchangeRate, 0, PHP_ROUND_HALF_UP);
        $feeCent = (int) round($convertedCent * ((float) $feeRate / 100), 0, PHP_ROUND_HALF_UP);

        return $convertedCent - $feeCent;
    }

    /**
     * 所有时间字段固定为 +8 时区 date 格式。
     */
    private function chinaDate(): string
    {
        return CarbonImmutable::now('Asia/Shanghai')->toDateString();
    }

    private function normalizeCurrencyType(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return 'R';
        }

        $currencyType = strtoupper(trim($value));
        if (!in_array($currencyType, ['R', 'U'], true)) {
            throw new InvalidArgumentException('currency_type 仅允许 R 或 U');
        }

        return $currencyType;
    }
}
