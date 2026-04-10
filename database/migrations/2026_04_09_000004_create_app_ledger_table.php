<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_ledger', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->bigInteger('tg_gid');
            $table->bigInteger('tg_uid');
            $table->bigInteger('tg_belong_uid');
            $table->bigInteger('tg_msg_id');
            $table->boolean('is_delete')->default(false);
            $table->bigInteger('amount');
            // 时间字段统一为 +8 时区下的 date 存储
            $table->date('created_at');
            $table->date('updated_at');

            $table->unique(['tg_gid', 'tg_msg_id']);
            $table->index(['tg_gid', 'created_at']);
            $table->index(['tg_uid', 'created_at']);
            $table->index(['tg_belong_uid', 'created_at']);
            $table->index(['tg_gid', 'is_delete', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_ledger');
    }
};
