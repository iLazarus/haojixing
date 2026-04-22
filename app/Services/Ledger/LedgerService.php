<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Models\AppLedger;
use App\Models\TgGroup;
use App\Models\TgUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class LedgerService
{
    public function list(int $limit = 200, ?int $tgGid = null): Collection
    {
        $query = AppLedger::query()->orderByDesc('id');

        if ($tgGid !== null) {
            $query->where('tg_gid', $tgGid);
        }

        return $query
            ->limit($limit)
            ->get();
    }

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

        $tgUid = (int) $data['tg_uid'];
        $tgBelongUid = (int) $data['tg_belong_uid'];
        $tgGid = (int) $data['tg_gid'];

        return AppLedger::query()->create([
            'tg_gid' => $tgGid,
            'tg_uid' => $tgUid,
            // 名称字段统一由 ID 反查，避免依赖调用方传入。
            'tg_nickname' => $this->resolveUserDisplayName($tgUid),
            'tg_belong_uid' => $tgBelongUid,
            'tg_belong_nickname' => $this->resolveUserDisplayName($tgBelongUid),
            'tg_msg_id' => (int) $data['tg_msg_id'],
            'is_delete' => (bool) ($data['is_delete'] ?? false),
            'amount' => (int) $data['amount'],
            'currency_type' => $this->normalizeCurrencyType($data['currency_type'] ?? null),
            'tg_g_name' => $this->resolveGroupName($tgGid),
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
        foreach (['amount', 'is_delete'] as $field) {
            if (array_key_exists($field, $data)) {
                $next[$field] = $data[$field];
            }
        }

        if (array_key_exists('currency_type', $data)) {
            $next['currency_type'] = $this->normalizeCurrencyType($data['currency_type']);
        }
        foreach (['tg_nickname', 'tg_belong_nickname', 'tg_g_name'] as $field) {
            if (array_key_exists($field, $data)) {
                $next[$field] = (string) ($data[$field] ?? '');
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
        if (!isset($data['tg_gid']) || (int) $data['tg_gid'] === 0) {
            throw new InvalidArgumentException('tg_gid 必须为非 0 整数且不能为空');
        }

        foreach (['tg_uid', 'tg_belong_uid', 'tg_msg_id'] as $key) {
            if (!isset($data[$key]) || (int) $data[$key] <= 0) {
                throw new InvalidArgumentException($key . ' 必须为正整数且不能为空');
            }
        }

        if (!isset($data['amount'])) {
            throw new InvalidArgumentException('amount 不能为空，且单位必须为分');
        }

        if (array_key_exists('currency_type', $data)) {
            $this->normalizeCurrencyType($data['currency_type']);
        }
    }

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

    private function resolveUserDisplayName(int $tgUid): string
    {
        if ($tgUid <= 0) {
            return '';
        }

        $user = TgUser::query()
            ->select(['tg_nickname', 'tg_username'])
            ->where('tg_uid', $tgUid)
            ->first();

        if (!$user instanceof TgUser) {
            return '';
        }

        $nickname = trim((string) ($user->tg_nickname ?? ''));
        if ($nickname !== '') {
            return $nickname;
        }

        return trim((string) ($user->tg_username ?? ''));
    }

    private function resolveGroupName(int $tgGid): string
    {
        if ($tgGid === 0) {
            return '';
        }

        $group = TgGroup::query()
            ->select(['tg_g_name'])
            ->where('tg_gid', $tgGid)
            ->first();

        return trim((string) ($group?->tg_g_name ?? ''));
    }
}
