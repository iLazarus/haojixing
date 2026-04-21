<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('app_rule', function (Blueprint $table): void {
            $table->string('method', 10)->default('POST');
            $table->index(['method', 'is_active']);
        });

        DB::table('app_rule')
            ->whereNull('method')
            ->orWhere('method', '')
            ->update(['method' => 'POST']);
    }

    public function down(): void
    {
        Schema::table('app_rule', function (Blueprint $table): void {
            $table->dropIndex(['method', 'is_active']);
            $table->dropColumn('method');
        });
    }
};
