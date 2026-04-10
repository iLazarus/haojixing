<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\TgUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class UserService
{
    public function list(int $limit = 50): Collection
    {
        return TgUser::query()
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    public function findByTgUid(int $tgUid): ?TgUser
    {
        return TgUser::query()->where('tg_uid', $tgUid)->first();
    }

    public function create(array $data): TgUser
    {
        if (!isset($data['tg_uid']) || (int) $data['tg_uid'] <= 0) {
            throw new InvalidArgumentException('tg_uid 必须为正整数且不能为空');
        }

        $chinaDate = $this->chinaDate();

        return TgUser::query()->create([
            'tg_uid' => (int) $data['tg_uid'],
            'tg_username' => $data['tg_username'] ?? null,
            'tg_nickname' => $data['tg_nickname'] ?? null,
            'created_at' => $chinaDate,
            'updated_at' => $chinaDate,
        ]);
    }

    public function updateByTgUid(int $tgUid, array $data): ?TgUser
    {
        $user = $this->findByTgUid($tgUid);
        if (!$user instanceof TgUser) {
            return null;
        }

        $next = ['updated_at' => $this->chinaDate()];

        if (array_key_exists('tg_username', $data)) {
            $next['tg_username'] = $data['tg_username'];
        }
        if (array_key_exists('tg_nickname', $data)) {
            $next['tg_nickname'] = $data['tg_nickname'];
        }

        $user->fill($next);
        $user->save();

        return $user->refresh();
    }

    public function deleteByTgUid(int $tgUid): int
    {
        return TgUser::query()->where('tg_uid', $tgUid)->delete();
    }

    private function chinaDate(): string
    {
        return CarbonImmutable::now('Asia/Shanghai')->toDateString();
    }
}
