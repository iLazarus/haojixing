<?php

declare(strict_types=1);

namespace App\Services\Member;

use App\Models\AppMember;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class MemberService
{
    public function list(int $limit = 200, ?int $tgGid = null): Collection
    {
        $query = AppMember::query()->orderByDesc('updated_at');

        if ($tgGid !== null) {
            $query->where('tg_gid', $tgGid);
        }

        return $query
            ->limit($limit)
            ->get();
    }

    public function listByGroup(int $tgGid, int $limit = 200): Collection
    {
        return AppMember::query()
            ->where('tg_gid', $tgGid)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    public function findOne(int $tgGid, int $tgUid): ?AppMember
    {
        return AppMember::query()
            ->where('tg_gid', $tgGid)
            ->where('tg_uid', $tgUid)
            ->first();
    }

    public function create(array $data): AppMember
    {
        $this->assertPayload($data);
        $chinaDate = $this->chinaDate();

        return AppMember::query()->create([
            'tg_gid' => (int) $data['tg_gid'],
            'tg_uid' => (int) $data['tg_uid'],
            'tg_g_name' => (string) ($data['tg_g_name'] ?? ''),
            'tg_nickname' => (string) ($data['tg_nickname'] ?? ''),
            'role' => (string) $data['role'],
            'is_active' => (bool) ($data['is_active'] ?? false),
            'created_at' => $chinaDate,
            'updated_at' => $chinaDate,
        ]);
    }

    public function updateOne(int $tgGid, int $tgUid, array $data): ?AppMember
    {
        $member = $this->findOne($tgGid, $tgUid);
        if (!$member instanceof AppMember) {
            return null;
        }

        $next = ['updated_at' => $this->chinaDate()];

        if (array_key_exists('role', $data)) {
            AppMember::assertRole((string) $data['role']);
            $next['role'] = (string) $data['role'];
        }
        if (array_key_exists('is_active', $data)) {
            $next['is_active'] = (bool) $data['is_active'];
        }
        foreach (['tg_g_name', 'tg_nickname'] as $field) {
            if (array_key_exists($field, $data)) {
                $next[$field] = (string) ($data[$field] ?? '');
            }
        }

        $member->fill($next);
        $member->save();

        return $member->refresh();
    }

    public function deleteOne(int $tgGid, int $tgUid): int
    {
        return AppMember::query()
            ->where('tg_gid', $tgGid)
            ->where('tg_uid', $tgUid)
            ->delete();
    }

    private function assertPayload(array $data): void
    {
        foreach (['tg_gid', 'tg_uid'] as $key) {
            if (!isset($data[$key]) || (int) $data[$key] <= 0) {
                throw new InvalidArgumentException($key . ' 必须为正整数且不能为空');
            }
        }

        AppMember::assertRole((string) ($data['role'] ?? ''));
    }

    private function chinaDate(): string
    {
        return CarbonImmutable::now('Asia/Shanghai')->toDateString();
    }
}
