<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('available_count')->default(0);
        });

        DB::statement(<<<'SQL'
            CREATE INDEX products_showcase_idx
                ON products (type, price, id)
             WHERE available_count > 0
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_showcase_idx');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('available_count');
        });
    }
};
