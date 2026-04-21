<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const REMARK = '默认规则：记账格式错误提示';
    private const REGULAR = '/^\+(?!\s*\d+(?:\.\d{1,2})?(?:\s*[RrUu])?(?:\s+@\S+)?\s*$).+/u';

    public function up(): void
    {
        $exists = DB::table('app_rule')
            ->where('remark', self::REMARK)
            ->where('regular', self::REGULAR)
            ->exists();

        if ($exists) {
            return;
        }

        $chinaDate = CarbonImmutable::now('Asia/Shanghai')->toDateString();
        $dataMap = [
            'reply_template' => "记账格式不正确，请使用：\n+12.34\n+12.34R\n+12.34 @someone\n说明：金额最多两位小数；若回复他人消息记账，会自动以被回复人为归属用户。",
        ];

        DB::table('app_rule')->insert([
            'remark' => self::REMARK,
            'regular' => self::REGULAR,
            'method' => 'POST',
            'api' => null,
            'data_map' => (string) json_encode($dataMap, JSON_UNESCAPED_UNICODE),
            'is_active' => true,
            'is_default' => true,
            'created_at' => $chinaDate,
            'updated_at' => $chinaDate,
        ]);
    }

    public function down(): void
    {
        DB::table('app_rule')
            ->where('remark', self::REMARK)
            ->where('regular', self::REGULAR)
            ->delete();
    }
};
