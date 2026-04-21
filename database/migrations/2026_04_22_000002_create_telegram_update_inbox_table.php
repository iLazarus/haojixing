<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tg_update_inbox', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->bigInteger('update_id')->nullable()->unique();
            $table->string('update_type', 64)->nullable();
            $table->bigInteger('chat_id')->nullable();
            $table->bigInteger('message_id')->nullable();
            $table->text('message_text')->nullable();
            $table->text('payload')->nullable();
            $table->string('status', 32)->default('已接收');
            $table->string('result_code', 64)->default('');
            $table->text('process_detail')->nullable();
            $table->unsignedInteger('attempt_count')->default(1);
            $table->text('last_error')->nullable();
            $table->dateTime('received_at');
            $table->dateTime('processed_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['status', 'received_at']);
            $table->index(['chat_id', 'message_id']);
            $table->index(['update_type', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tg_update_inbox');
    }
};
