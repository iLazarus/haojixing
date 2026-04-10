<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tg_user', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('tg_uid');
            $table->string('tg_username', 64)->nullable();
            $table->string('tg_nickname', 128)->nullable();
            // 时间字段统一为 +8 时区下的 date 存储
            $table->date('created_at');
            $table->date('updated_at');

            $table->unique('tg_uid');
            $table->index('tg_username');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tg_user');
    }
};
