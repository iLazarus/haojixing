<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Models\AppLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class LedgerService
{
    public function listByGroup(int $tgGid, int $limit = 200): Collection
    {
        return AppLedger::query()
            ->where('tg_gid', $tgGid)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function findById(int $id): ?AppLedger
    {
        return AppLedger::query()->find($id);
    }

    public function create(array $data): AppLedger
    {
        $this->assertPayload($data);
        $chinaDate = $this->chinaDate();

        return AppLedger::query()->create([
            'tg_gid' => (int) $data['tg_gid'],
            'tg_uid' => (int) $data['tg_uid'],
            'tg_belong_uid' => (int) $data['tg_belong_uid'],
            'tg_msg_id' => (int) $data['tg_msg_id'],
            'is_delete' => (bool) ($data['is_delete'] ?? false),
            'amount' => (int) $data['amount'],
            'created_at' => $chinaDate,
            'updated_at' => $chinaDate,
        ]);
    }

    public function updateById(int $id, array $data): ?AppLedger
    {
        $ledger = $this->findById($id);
        if (!$ledger instanceof AppLedger) {
            return null;
        }

        $next = ['updated_at' => $this->chinaDate()];
        foreach (['tg_gid', 'tg_uid', 'tg_belong_uid', 'tg_msg_id', 'amount', 'is_delete'] as $field) {
            if (array_key_exists($field, $data)) {
                $next[$field] = $data[$field];
            }
        }

        $ledger->fill($next);
        $ledger->save();

        return $ledger->refresh();
    }

    public function deleteById(int $id): int
    {
        return AppLedger::query()->where('id', $id)->delete();
    }

    public function softDeleteById(int $id): ?AppLedger
    {
        return $this->updateById($id, ['is_delete' => true]);
    }

    private function assertPayload(array $data): void
    {
        foreach (['tg_gid', 'tg_uid', 'tg_belong_uid', 'tg_msg_id'] as $key) {
            if (!isset($data[$key]) || (int) $data[$key] <= 0) {
                throw new InvalidArgumentException($key . ' 必须为正整数且不能为空');
            }
        }

        if (!isset($data['amount'])) {
            throw new InvalidArgumentException('amount 不能为空，且单位必须为分');
        }
    }

    private function chinaDate(): string
    {
        return CarbonImmutable::now('Asia/Shanghai')->toDateString();
    }
}
