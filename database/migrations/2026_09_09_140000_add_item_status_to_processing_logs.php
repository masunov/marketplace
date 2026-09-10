<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_processing_logs', function (Blueprint $table): void {
            $table->string('item_status')->nullable()->after('previous_status');
            $table->string('previous_item_status')->nullable()->after('item_status');
        });

        DB::statement('ALTER TABLE order_processing_logs ALTER COLUMN order_status DROP NOT NULL');
    }

    public function down(): void
    {
        Schema::table('order_processing_logs', function (Blueprint $table): void {
            $table->dropColumn(['item_status', 'previous_item_status']);
        });

        DB::statement('ALTER TABLE order_processing_logs ALTER COLUMN order_status SET NOT NULL');
    }
};
