<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_keys', function (Blueprint $table): void {
            $table->uuid('order_item_id')->nullable()->after('order_id');
        });

        $this->fillOrderItems();

        Schema::table('vendor_keys', function (Blueprint $table): void {
            $table->dropUnique(['order_id']);
            $table->dropUnique(['key', 'vendor']);
            $table->dropIndex(['key']);

            $table->unique('order_item_id');
            $table->unique('key');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_keys', function (Blueprint $table): void {
            $table->dropUnique(['key']);
            $table->dropUnique(['order_item_id']);

            $table->index('key');
            $table->unique(['key', 'vendor']);
            $table->unique('order_id');

            $table->dropColumn('order_item_id');
        });
    }

    private function fillOrderItems(): void
    {
        DB::statement(<<<'SQL'
            UPDATE vendor_keys vk
               SET order_item_id = i.id
              FROM order_items i
             WHERE i.order_id = vk.order_id
               AND vk.order_item_id IS NULL
        SQL);

        DB::statement('ALTER TABLE vendor_keys ALTER COLUMN order_item_id SET NOT NULL');
    }
};
