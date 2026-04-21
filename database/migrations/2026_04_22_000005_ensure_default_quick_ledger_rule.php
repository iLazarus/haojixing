<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const LEDGER_REGEX = '/^\+(\d+(?:\.\d{1,2})?)([RrUu])?(?:\s+@(\S+))?$/u';

    public function up(): void
    {
        $chinaDate = CarbonImmutable::now('Asia/Shanghai')->toDateString();

        $candidate = DB::table('app_rule')
            ->where('api', 'like', '%/api/v1/ledgers%')
            ->orderBy('id')
            ->first();

        if ($candidate !== null) {
            DB::table('app_rule')
                ->where('id', (int) $candidate->id)
                ->update([
                    'remark' => trim((string) ($candidate->remark ?? '')) !== '' ? (string) $candidate->remark : '记账',
                    'regular' => self::LEDGER_REGEX,
                    'method' => 'POST',
                    'is_active' => true,
                    'is_default' => true,
                    'updated_at' => $chinaDate,
                ]);

            return;
        }

        $dataMap = [
            'api_payload' => [
                'tg_gid' => '{{tg_gid}}',
                'tg_uid' => '{{tg_uid}}',
                'tg_belong_uid' => '{{tg_uid}}',
                'tg_msg_id' => '{{tg_msg_id}}',
                'amount' => '{{1}}',
                'is_delete' => false,
            ],
            'reply_template' => '记账成功，金额={{1}}，流水ID={{result.data.id}}',
        ];

        DB::table('app_rule')->insert([
            'remark' => '记账',
            'regular' => self::LEDGER_REGEX,
            'method' => 'POST',
            'api' => 'http://localhost:9001/api/v1/ledgers',
            'data_map' => (string) json_encode($dataMap, JSON_UNESCAPED_UNICODE),
            'is_active' => true,
            'is_default' => true,
            'created_at' => $chinaDate,
            'updated_at' => $chinaDate,
        ]);
    }

    public function down(): void
    {
        // 不回滚规则状态，避免线上意外失效。
    }
};
