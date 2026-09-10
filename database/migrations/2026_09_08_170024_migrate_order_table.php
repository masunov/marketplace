<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->fillOrderItems();
        $this->cleanUpOrders();
    }

    public function down(): void
    {
        $this->restoreOrderColumns();
        $this->fillOrderColumnsBack();
    }

    private function fillOrderItems(): void
    {
        DB::statement(
            <<<'SQL'
            INSERT INTO order_items (
                id,
                order_id,
                product_id,
                request_id,
                price,
                currency,
                status,
                delivery_cycle_started_at,
                related_content_type,
                related_content_id,
                created_at,
                updated_at
            )
            SELECT gen_random_uuid(),
                   o.id,
                   o.product_id,
                   o.request_id,
                   o.price,
                   o.currency,
                   CASE o.status
                       WHEN 'delivered'       THEN 'delivered'
                       WHEN 'delivering'      THEN 'delivering'
                       WHEN 'out_of_stock'    THEN 'out_of_stock'
                       WHEN 'delivery_failed' THEN 'delivery_failed'
                       ELSE 'pending'
                   END,
                   o.delivery_cycle_started_at,
                   o.related_content_type,
                   o.related_content_id,
                   o.created_at,
                   o.updated_at
              FROM orders o
             WHERE NOT EXISTS (
                       SELECT 1
                         FROM order_items i
                        WHERE i.order_id = o.id
                   )
        SQL
        );
    }

    private function cleanUpOrders(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                                   'product_id',
                                   'request_id',
                                   'delivery_cycle_started_at',
                                   'related_content_type',
                                   'related_content_id',
                               ]);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->renameColumn('price', 'total_amount');
        });
    }

    private function restoreOrderColumns(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->renameColumn('total_amount', 'price');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->uuid('product_id')->nullable();
            $table->uuid('request_id')->nullable()->index();
            $table->timestamp('delivery_cycle_started_at')->nullable();
            $table->string('related_content_type')->nullable();
            $table->uuid('related_content_id')->nullable();

            $table->index(['related_content_type', 'related_content_id']);
        });
    }

    private function fillOrderColumnsBack(): void
    {
        DB::statement(
            <<<'SQL'
            UPDATE orders o
               SET product_id                = i.product_id,
                   request_id                = i.request_id,
                   delivery_cycle_started_at = i.delivery_cycle_started_at,
                   related_content_type      = i.related_content_type,
                   related_content_id        = i.related_content_id
              FROM (
                       SELECT DISTINCT ON (order_id) *
                         FROM order_items
                        ORDER BY order_id, created_at, id
                   ) i
             WHERE i.order_id = o.id
        SQL
        );
    }
};
