<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->index();
            $table->foreignUuid('order_item_id')->nullable()->index();
            $table->string('vendor_external_id')->nullable()->index();
            $table->decimal('price', 12);
            $table->string('currency');
            $table->string('status');
            $table->string('type');
            $table->string('failed_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX order_transactions_charge_unique
                ON order_transactions (order_item_id)
             WHERE type = 'charge'
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX order_transactions_refund_unique
                ON order_transactions (order_item_id)
             WHERE type = 'refund' AND status = 'succeeded'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_transactions');
    }
};
