<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tg_group', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('tg_gid');
            $table->bigInteger('tg_oid');
            $table->boolean('is_open')->default(true);
            $table->char('base_currency', 3)->default('R');
            $table->char('quote_currency', 3)->default('U');
            $table->decimal('exchange_rate', 20, 10)->default('1');
            $table->decimal('fee_rate', 7, 4)->default('0');
            $table->unsignedTinyInteger('period_point')->default(0);
            $table->unsignedInteger('period_duration')->default(1440);
            // 时间字段统一为 +8 时区下的 date 存储
            $table->date('created_at');
            $table->date('updated_at');

            $table->unique('tg_gid');
            $table->index('tg_oid');
            $table->index(['is_open', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tg_group');
    }
};
