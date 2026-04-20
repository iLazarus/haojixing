<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('app_rule', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->after('is_active');
            $table->index(['is_default', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('app_rule', function (Blueprint $table): void {
            $table->dropIndex(['is_default', 'is_active']);
            $table->dropColumn('is_default');
        });
    }
};
