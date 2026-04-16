<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('group_rule', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->bigInteger('tg_gid');
            $table->bigInteger('app_rule_id');
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('stop_on_match')->default(true);
            $table->boolean('is_active')->default(true);
            $table->date('created_at');
            $table->date('updated_at');

            $table->unique(['tg_gid', 'app_rule_id']);
            $table->index(['tg_gid', 'is_active', 'priority']);
            $table->index(['app_rule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_rule');
    }
};
