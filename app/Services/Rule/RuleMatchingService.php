<?php

declare(strict_types=1);

namespace App\Services\Rule;

use App\Models\AppRule;
use App\Models\GroupRule;
use App\Models\RuleHitLog;
use Carbon\CarbonImmutable;

class RuleMatchingService
{
    public function __construct(private readonly RuleActionExecutor $actionExecutor)
    {
    }

    public function match(int $tgGid, int $tgMsgId, string $message, bool $executeApi = false, array $context = []): array
    {
        $rows = GroupRule::query()
            ->select([
                'group_rule.id as group_rule_id',
                'group_rule.tg_gid',
                'group_rule.app_rule_id',
                'group_rule.priority',
                'group_rule.stop_on_match',
                'group_rule.is_active as group_rule_active',
                'app_rule.id as rule_id',
                'app_rule.remark',
                'app_rule.regular',
                'app_rule.api',
                'app_rule.data_map',
                'app_rule.is_active as rule_active',
            ])
            ->join('app_rule', 'group_rule.app_rule_id', '=', 'app_rule.id')
            ->where('group_rule.tg_gid', $tgGid)
            ->where('group_rule.is_active', true)
            ->where('app_rule.is_active', true)
            ->orderBy('group_rule.priority')
            ->orderBy('group_rule.id')
            ->get();

        $hits = [];

        /** @var object $row */
        foreach ($rows as $row) {
            $alreadyHit = RuleHitLog::query()
                ->where('tg_gid', $tgGid)
                ->where('tg_msg_id', $tgMsgId)
                ->where('app_rule_id', (int) $row->app_rule_id)
                ->exists();

            if ($alreadyHit) {
                continue;
            }

            $regex = (string) $row->regular;
            $matchResult = @preg_match($regex, $message, $matches);
            if ($matchResult !== 1) {
                continue;
            }

            $rule = new AppRule();
            $rule->id = (int) $row->rule_id;
            $rule->remark = (string) $row->remark;
            $rule->regular = $regex;
            $rule->api = $row->api !== null ? (string) $row->api : null;
            $rule->data_map = $row->data_map !== null ? (string) $row->data_map : null;
            $rule->is_active = (bool) $row->rule_active;

            $action = $this->actionExecutor->buildAction($rule, $matches, $context);
            $apiResult = $this->actionExecutor->executeApiIfNeeded($action, $executeApi);

            $this->writeHitLog($tgGid, $tgMsgId, (int) $row->app_rule_id);

            $hits[] = [
                'group_rule_id' => (int) $row->group_rule_id,
                'app_rule_id' => (int) $row->app_rule_id,
                'priority' => (int) $row->priority,
                'stop_on_match' => (bool) $row->stop_on_match,
                'remark' => (string) $row->remark,
                'matched' => $matches,
                'action' => $action,
                'api_result' => $apiResult,
            ];

            if ((bool) $row->stop_on_match) {
                break;
            }
        }

        return [
            'tg_gid' => $tgGid,
            'tg_msg_id' => $tgMsgId,
            'message' => $message,
            'hit_count' => count($hits),
            'hits' => $hits,
        ];
    }

    private function writeHitLog(int $tgGid, int $tgMsgId, int $appRuleId): void
    {
        $chinaDate = CarbonImmutable::now('Asia/Shanghai')->toDateString();

        RuleHitLog::query()->create([
            'tg_gid' => $tgGid,
            'tg_msg_id' => $tgMsgId,
            'app_rule_id' => $appRuleId,
            'created_at' => $chinaDate,
            'updated_at' => $chinaDate,
        ]);
    }
}
