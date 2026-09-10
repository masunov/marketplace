<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TABLE = 'order_processing_logs';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->uuid('parent_log_id')->nullable()->after('id');
            $table->string('type')->nullable()->after('order_id');
            $table->uuid('order_item_id')->nullable()->after('order_id');
            $table->uuid('attempt_request_id')->nullable()->after('request_id');
            $table->jsonb('payload')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn([
                'parent_log_id',
                'type',
                'order_item_id',
                'attempt_request_id',
                'payload',
            ]);
        });
    }
};
