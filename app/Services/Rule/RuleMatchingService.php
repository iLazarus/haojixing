<?php

declare(strict_types=1);

namespace App\Services\Rule;

use App\Models\AppRule;
use App\Models\GroupRule;
use App\Models\RuleHitLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RuleMatchingService
{
    public function __construct(private readonly RuleActionExecutor $actionExecutor)
    {
    }

    public function match(int $tgGid, int $tgMsgId, string $message, bool $executeApi = false, array $context = []): array
    {
        $startedAt = microtime(true);
        $mergedRows = $this->loadMergedRules($tgGid);
        $runtimeContext = array_merge($context, [
            'tg_gid' => $tgGid,
            'tg_msg_id' => $tgMsgId,
            'message' => $message,
        ]);

        $alreadyHitRuleIds = RuleHitLog::query()
            ->where('tg_gid', $tgGid)
            ->where('tg_msg_id', $tgMsgId)
            ->pluck('app_rule_id')
            ->all();
        $alreadyHitMap = array_fill_keys(array_map(static fn ($id): int => (int) $id, $alreadyHitRuleIds), true);

        Log::channel('stderr')->info('rule_match_candidates_loaded', [
            'tg_gid' => $tgGid,
            'tg_msg_id' => $tgMsgId,
            'message_preview' => mb_substr($message, 0, 120),
            'group_rule_count' => count(array_filter($mergedRows, static fn (array $row): bool => ($row['source'] ?? '') === 'group')),
            'default_rule_count' => count(array_filter($mergedRows, static fn (array $row): bool => ($row['source'] ?? '') === 'default')),
            'merged_rule_count' => count($mergedRows),
            'already_hit_count' => count($alreadyHitMap),
            'execute_api' => $executeApi,
        ]);

        $hits = [];

        /** @var array<string, mixed> $row */
        foreach ($mergedRows as $row) {
            $appRuleId = (int) ($row['app_rule_id'] ?? 0);
            if ($appRuleId <= 0) {
                continue;
            }

            if (isset($alreadyHitMap[$appRuleId])) {
                continue;
            }

            $regex = (string) ($row['regular'] ?? '');
            $matchResult = @preg_match($regex, $message, $matches);
            if ($matchResult !== 1) {
                continue;
            }

            $rule = new AppRule();
            $rule->id = (int) ($row['rule_id'] ?? 0);
            $rule->remark = (string) ($row['remark'] ?? '');
            $rule->regular = $regex;
            $rule->method = array_key_exists('method', $row) && $row['method'] !== null ? (string) $row['method'] : 'POST';
            $rule->api = array_key_exists('api', $row) && $row['api'] !== null ? (string) $row['api'] : null;
            $rule->data_map = array_key_exists('data_map', $row) && $row['data_map'] !== null ? (string) $row['data_map'] : null;
            $rule->is_active = (bool) ($row['rule_active'] ?? true);
            $rule->is_default = (bool) ($row['rule_default'] ?? false);

            $action = $this->actionExecutor->buildAction($rule, $matches, $runtimeContext);
            $apiResult = $this->actionExecutor->executeApiIfNeeded($action, $executeApi);
            $action['reply_text'] = $this->actionExecutor->renderReplyText($action, $matches, $runtimeContext, $apiResult);

            $this->writeHitLog($tgGid, $tgMsgId, $appRuleId);
            $alreadyHitMap[$appRuleId] = true;

            $hits[] = [
                'group_rule_id' => array_key_exists('group_rule_id', $row) && $row['group_rule_id'] !== null ? (int) $row['group_rule_id'] : null,
                'app_rule_id' => $appRuleId,
                'priority' => (int) ($row['priority'] ?? 100000),
                'stop_on_match' => (bool) ($row['stop_on_match'] ?? true),
                'remark' => (string) ($row['remark'] ?? ''),
                'regular' => $regex,
                'source' => (string) ($row['source'] ?? 'group'),
                'matched' => $matches,
                'action' => $action,
                'api_result' => $apiResult,
            ];

            Log::channel('stderr')->info('rule_match_hit', [
                'tg_gid' => $tgGid,
                'tg_msg_id' => $tgMsgId,
                'app_rule_id' => $appRuleId,
                'source' => (string) ($row['source'] ?? 'group'),
                'regular' => $regex,
                'mode' => (string) ($action['mode'] ?? 'noop'),
                'reply_preview' => is_string($action['reply_text'] ?? null) ? mb_substr((string) $action['reply_text'], 0, 120) : null,
            ]);

            if ((bool) ($row['stop_on_match'] ?? true)) {
                break;
            }
        }

        Log::channel('stderr')->info('rule_match_finished', [
            'tg_gid' => $tgGid,
            'tg_msg_id' => $tgMsgId,
            'hit_count' => count($hits),
            'hit_rule_ids' => array_map(static fn (array $hit): int => (int) ($hit['app_rule_id'] ?? 0), $hits),
            'cost_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

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

        try {
            RuleHitLog::query()->create([
                'tg_gid' => $tgGid,
                'tg_msg_id' => $tgMsgId,
                'app_rule_id' => $appRuleId,
                'created_at' => $chinaDate,
                'updated_at' => $chinaDate,
            ]);
        } catch (QueryException $e) {
            // 并发重试场景下可能命中唯一键冲突，这里忽略并继续。
            if (str_contains(strtolower($e->getMessage()), 'unique') || str_contains(strtolower($e->getMessage()), 'duplicate')) {
                return;
            }

            throw $e;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadMergedRules(int $tgGid): array
    {
        $version = (int) Cache::get('rule:cache:version', 1);

        return Cache::remember("rule:merged:v{$version}:{$tgGid}", now()->addSeconds(3), function () use ($tgGid, $version): array {
            $boundRows = GroupRule::query()
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
                    'app_rule.method',
                    'app_rule.api',
                    'app_rule.data_map',
                    'app_rule.is_active as rule_active',
                    'app_rule.is_default as rule_default',
                ])
                ->join('app_rule', 'group_rule.app_rule_id', '=', 'app_rule.id')
                ->where('group_rule.tg_gid', $tgGid)
                ->where('group_rule.is_active', true)
                ->where('app_rule.is_active', true)
                ->orderBy('group_rule.priority')
                ->orderBy('group_rule.id')
                ->get();

            $boundRegularSet = [];
            $mergedRows = [];

            foreach ($boundRows as $boundRow) {
                $regular = trim((string) ($boundRow->regular ?? ''));
                if ($regular !== '') {
                    $boundRegularSet[$regular] = true;
                }

                $mergedRows[] = [
                    'group_rule_id' => $boundRow->group_rule_id !== null ? (int) $boundRow->group_rule_id : null,
                    'tg_gid' => (int) ($boundRow->tg_gid ?? $tgGid),
                    'app_rule_id' => (int) ($boundRow->app_rule_id ?? 0),
                    'priority' => (int) ($boundRow->priority ?? 100000),
                    'stop_on_match' => (bool) ($boundRow->stop_on_match ?? true),
                    'group_rule_active' => (bool) ($boundRow->group_rule_active ?? true),
                    'rule_id' => (int) ($boundRow->rule_id ?? 0),
                    'remark' => (string) ($boundRow->remark ?? ''),
                    'regular' => $regular,
                    'method' => $boundRow->method !== null ? (string) $boundRow->method : 'POST',
                    'api' => $boundRow->api !== null ? (string) $boundRow->api : null,
                    'data_map' => $boundRow->data_map !== null ? (string) $boundRow->data_map : null,
                    'rule_active' => (bool) ($boundRow->rule_active ?? true),
                    'rule_default' => (bool) ($boundRow->rule_default ?? false),
                    'source' => 'group',
                ];
            }

            $defaultRows = Cache::remember("rule:default:active:v{$version}", now()->addSeconds(5), function () {
                return AppRule::query()
                    ->select([
                        'id as rule_id',
                        'remark',
                        'regular',
                        'method',
                        'api',
                        'data_map',
                        'is_active as rule_active',
                        'is_default as rule_default',
                    ])
                    ->where('is_active', true)
                    ->where('is_default', true)
                    ->orderBy('id')
                    ->get();
            });

            $defaultRegularSet = [];
            foreach ($defaultRows as $defaultRule) {
                $regular = trim((string) ($defaultRule->regular ?? ''));
                if ($regular === '' || isset($boundRegularSet[$regular]) || isset($defaultRegularSet[$regular])) {
                    continue;
                }

                $defaultRegularSet[$regular] = true;
                $mergedRows[] = [
                    'group_rule_id' => null,
                    'tg_gid' => $tgGid,
                    'app_rule_id' => (int) $defaultRule->rule_id,
                    'priority' => 100000,
                    'stop_on_match' => true,
                    'group_rule_active' => true,
                    'rule_id' => (int) $defaultRule->rule_id,
                    'remark' => (string) ($defaultRule->remark ?? ''),
                    'regular' => $regular,
                    'method' => $defaultRule->method !== null ? (string) $defaultRule->method : 'POST',
                    'api' => $defaultRule->api !== null ? (string) $defaultRule->api : null,
                    'data_map' => $defaultRule->data_map !== null ? (string) $defaultRule->data_map : null,
                    'rule_active' => (bool) ($defaultRule->rule_active ?? true),
                    'rule_default' => (bool) ($defaultRule->rule_default ?? true),
                    'source' => 'default',
                ];
            }

            return $mergedRows;
        });
    }
}
