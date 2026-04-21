<?php

declare(strict_types=1);

namespace App\Services\Rule;

use App\Models\AppRule;
use App\Models\GroupRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class GroupRuleService
{
    public function list(int $limit = 200, ?int $tgGid = null): Collection
    {
        $query = GroupRule::query()->orderBy('priority')->orderByDesc('updated_at');

        if ($tgGid !== null) {
            $query->where('tg_gid', $tgGid);
        }

        return $query
            ->limit($limit)
            ->get();
    }

    public function listByGroup(int $tgGid, int $limit = 200): Collection
    {
        return GroupRule::query()
            ->where('tg_gid', $tgGid)
            ->orderBy('priority')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    public function findOne(int $tgGid, int $appRuleId): ?GroupRule
    {
        return GroupRule::query()
            ->where('tg_gid', $tgGid)
            ->where('app_rule_id', $appRuleId)
            ->first();
    }

    public function create(array $data): GroupRule
    {
        $exists = AppRule::query()->where('id', (int) $data['app_rule_id'])->exists();
        if (!$exists) {
            throw new InvalidArgumentException('app_rule_id 对应规则不存在');
        }

        $chinaDate = $this->chinaDate();

        return GroupRule::query()->create([
            'tg_gid' => (int) $data['tg_gid'],
            'app_rule_id' => (int) $data['app_rule_id'],
            'priority' => (int) ($data['priority'] ?? 100),
            'stop_on_match' => (bool) ($data['stop_on_match'] ?? true),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_at' => $chinaDate,
            'updated_at' => $chinaDate,
        ]);
    }

    public function updateOne(int $tgGid, int $appRuleId, array $data): ?GroupRule
    {
        $rule = $this->findOne($tgGid, $appRuleId);
        if (!$rule instanceof GroupRule) {
            return null;
        }

        $next = [
            'updated_at' => $this->chinaDate(),
        ];

        foreach (['priority', 'stop_on_match', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $next[$field] = $data[$field];
            }
        }

        $rule->fill($next);
        $rule->save();

        return $rule->refresh();
    }

    public function deleteOne(int $tgGid, int $appRuleId): int
    {
        return GroupRule::query()
            ->where('tg_gid', $tgGid)
            ->where('app_rule_id', $appRuleId)
            ->delete();
    }

    private function chinaDate(): string
    {
        return CarbonImmutable::now('Asia/Shanghai')->toDateString();
    }
}
