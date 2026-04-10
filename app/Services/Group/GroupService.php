<?php

declare(strict_types=1);

namespace App\Services\Group;

use App\Models\TgGroup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class GroupService
{
    public function list(int $limit = 50): Collection
    {
        return TgGroup::query()
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    public function findByTgGid(int $tgGid): ?TgGroup
    {
        return TgGroup::query()->where('tg_gid', $tgGid)->first();
    }

    public function create(array $data): TgGroup
    {
        $this->assertPayload($data);
        $chinaDate = $this->chinaDate();

        return TgGroup::query()->create([
            'tg_gid' => (int) $data['tg_gid'],
            'tg_oid' => (int) $data['tg_oid'],
            'is_open' => (bool) ($data['is_open'] ?? true),
            'base_currency' => strtoupper((string) ($data['base_currency'] ?? 'R')),
            'quote_currency' => strtoupper((string) ($data['quote_currency'] ?? 'U')),
            'exchange_rate' => (string) ($data['exchange_rate'] ?? '1'),
            'fee_rate' => (string) ($data['fee_rate'] ?? '0'),
            'period_point' => (int) ($data['period_point'] ?? 0),
            'period_duration' => (int) ($data['period_duration'] ?? 1440),
            'created_at' => $chinaDate,
            'updated_at' => $chinaDate,
        ]);
    }

    public function updateByTgGid(int $tgGid, array $data): ?TgGroup
    {
        $group = $this->findByTgGid($tgGid);
        if (!$group instanceof TgGroup) {
            return null;
        }

        $next = [
            'updated_at' => $this->chinaDate(),
        ];

        foreach (['tg_oid', 'is_open', 'exchange_rate', 'fee_rate', 'period_point', 'period_duration'] as $field) {
            if (array_key_exists($field, $data)) {
                $next[$field] = $data[$field];
            }
        }

        if (array_key_exists('base_currency', $data)) {
            $next['base_currency'] = strtoupper((string) $data['base_currency']);
        }
        if (array_key_exists('quote_currency', $data)) {
            $next['quote_currency'] = strtoupper((string) $data['quote_currency']);
        }

        $group->fill($next);
        $group->save();

        return $group->refresh();
    }

    public function deleteByTgGid(int $tgGid): int
    {
        return TgGroup::query()->where('tg_gid', $tgGid)->delete();
    }

    private function assertPayload(array $data): void
    {
        foreach (['tg_gid', 'tg_oid'] as $key) {
            if (!isset($data[$key]) || (int) $data[$key] <= 0) {
                throw new InvalidArgumentException($key . ' 必须为正整数且不能为空');
            }
        }

        $feeRate = (float) ($data['fee_rate'] ?? 0);
        if ($feeRate < 0 || $feeRate > 100) {
            throw new InvalidArgumentException('fee_rate 范围必须是 0-100（百分比）');
        }

        $periodPoint = (int) ($data['period_point'] ?? 0);
        if ($periodPoint < 0 || $periodPoint > 23) {
            throw new InvalidArgumentException('period_point 范围必须是 0-23（24 小时制）');
        }

        $periodDuration = (int) ($data['period_duration'] ?? 1440);
        if ($periodDuration <= 0) {
            throw new InvalidArgumentException('period_duration 必须大于 0，单位为分钟');
        }
    }

    private function chinaDate(): string
    {
        return CarbonImmutable::now('Asia/Shanghai')->toDateString();
    }
}
