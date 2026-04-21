<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('app_ledger', function (Blueprint $table): void {
            $table->char('currency_type', 1)->default('R')->after('amount');
            $table->index(['currency_type', 'created_at']);
        });

        DB::table('app_ledger')->update(['currency_type' => 'R']);
    }

    public function down(): void
    {
        Schema::table('app_ledger', function (Blueprint $table): void {
            $table->dropIndex(['currency_type', 'created_at']);
            $table->dropColumn('currency_type');
        });
    }
};
