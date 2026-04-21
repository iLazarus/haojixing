<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('app_ledger', function (Blueprint $table): void {
            $table->string('tg_g_name', 255)->default('')->after('currency_type');
            $table->string('tg_nickname', 255)->default('')->after('tg_uid');
            $table->string('tg_belong_nickname', 255)->default('')->after('tg_belong_uid');
            $table->index(['tg_gid', 'tg_nickname']);
            $table->index(['tg_gid', 'tg_belong_nickname']);
        });

        Schema::table('tg_group', function (Blueprint $table): void {
            $table->string('tg_g_name', 255)->default('')->after('tg_oid');
            $table->string('tg_o_nickname', 255)->default('')->after('tg_g_name');
        });

        Schema::table('app_member', function (Blueprint $table): void {
            $table->string('tg_g_name', 255)->default('')->after('tg_uid');
            $table->string('tg_nickname', 255)->default('')->after('tg_g_name');
            $table->index(['tg_gid', 'tg_nickname']);
        });
    }

    public function down(): void
    {
        Schema::table('app_member', function (Blueprint $table): void {
            $table->dropIndex(['tg_gid', 'tg_nickname']);
            $table->dropColumn(['tg_g_name', 'tg_nickname']);
        });

        Schema::table('tg_group', function (Blueprint $table): void {
            $table->dropColumn(['tg_g_name', 'tg_o_nickname']);
        });

        Schema::table('app_ledger', function (Blueprint $table): void {
            $table->dropIndex(['tg_gid', 'tg_nickname']);
            $table->dropIndex(['tg_gid', 'tg_belong_nickname']);
            $table->dropColumn(['tg_g_name', 'tg_nickname', 'tg_belong_nickname']);
        });
    }
};
