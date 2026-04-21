<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tg_update_inbox')) {
            return;
        }

        if (!Schema::hasColumn('tg_update_inbox', 'message_text')) {
            Schema::table('tg_update_inbox', function (Blueprint $table): void {
                $table->text('message_text')->nullable()->after('message_id');
            });
        }

        DB::table('tg_update_inbox')->where('status', 'received')->update(['status' => '已接收']);
        DB::table('tg_update_inbox')->where('status', 'done')->update(['status' => '已处理']);
        DB::table('tg_update_inbox')->where('status', 'ignored')->update(['status' => '已忽略']);
        DB::table('tg_update_inbox')->where('status', 'duplicate')->update(['status' => '重复更新']);
        DB::table('tg_update_inbox')->where('status', 'failed')->update(['status' => '处理失败']);

        DB::table('tg_update_inbox')->where('result_code', 'no_rule_hit')->update(['result_code' => '未命中任何规则']);
        DB::table('tg_update_inbox')->where('result_code', 'hit_without_reply_text')->update(['result_code' => '规则命中但无需回复']);
        DB::table('tg_update_inbox')->where('result_code', 'rule_replied')->update(['result_code' => '规则命中并已回复']);
        DB::table('tg_update_inbox')->where('result_code', 'manual_refresh_done')->update(['result_code' => '手动刷新完成']);
        DB::table('tg_update_inbox')->where('result_code', 'refresh_forbidden')->update(['result_code' => '刷新被拒绝']);
        DB::table('tg_update_inbox')->where('result_code', 'internal_error')->update(['result_code' => '内部异常']);
        DB::table('tg_update_inbox')->where('result_code', 'duplicate_update')->update(['result_code' => '更新重复（去重）']);
    }

    public function down(): void
    {
        if (!Schema::hasTable('tg_update_inbox')) {
            return;
        }

        if (Schema::hasColumn('tg_update_inbox', 'message_text')) {
            Schema::table('tg_update_inbox', function (Blueprint $table): void {
                $table->dropColumn('message_text');
            });
        }
    }
};
