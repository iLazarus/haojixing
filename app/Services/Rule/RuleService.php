<?php

declare(strict_types=1);

namespace App\Services\Rule;

use App\Models\AppRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class RuleService
{
    public function list(int $limit = 100): Collection
    {
        return AppRule::query()
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    public function findById(int $id): ?AppRule
    {
        return AppRule::query()->find($id);
    }

    public function create(array $data): AppRule
    {
        $chinaDate = $this->chinaDate();

        return AppRule::query()->create([
            'remark' => (string) ($data['remark'] ?? ''),
            'regular' => (string) $data['regular'],
            'api' => array_key_exists('api', $data) ? (string) $data['api'] : null,
            'data_map' => array_key_exists('data_map', $data) ? (string) $data['data_map'] : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_at' => $chinaDate,
            'updated_at' => $chinaDate,
        ]);
    }

    public function updateById(int $id, array $data): ?AppRule
    {
        $rule = $this->findById($id);
        if (!$rule instanceof AppRule) {
            return null;
        }

        $next = [
            'updated_at' => $this->chinaDate(),
        ];

        foreach (['remark', 'regular', 'api', 'data_map', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $next[$field] = $data[$field];
            }
        }

        $rule->fill($next);
        $rule->save();

        return $rule->refresh();
    }

    public function deleteById(int $id): int
    {
        return AppRule::query()->where('id', $id)->delete();
    }

    private function chinaDate(): string
    {
        return CarbonImmutable::now('Asia/Shanghai')->toDateString();
    }
}
