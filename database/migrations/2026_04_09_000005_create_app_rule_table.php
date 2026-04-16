<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_rule', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('remark', 255)->default('');
            $table->string('regular', 512);
            $table->text('api')->nullable();
            $table->text('data_map')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('created_at');
            $table->date('updated_at');

            $table->index(['is_active', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_rule');
    }
};
