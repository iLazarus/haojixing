<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('rule_hit_log', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->bigInteger('tg_gid');
            $table->bigInteger('tg_msg_id');
            $table->bigInteger('app_rule_id');
            $table->date('created_at');
            $table->date('updated_at');

            $table->unique(['tg_gid', 'tg_msg_id', 'app_rule_id']);
            $table->index(['tg_gid', 'created_at']);
            $table->index(['app_rule_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_hit_log');
    }
};
