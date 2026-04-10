<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_member', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('tg_gid');
            $table->bigInteger('tg_uid');
            $table->string('role', 16);
            $table->boolean('is_active')->default(false);
            // 时间字段统一为 +8 时区下的 date 存储
            $table->date('created_at');
            $table->date('updated_at');

            $table->unique(['tg_gid', 'tg_uid']);
            $table->index(['tg_uid', 'is_active']);
            $table->index(['tg_gid', 'role', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_member');
    }
};
