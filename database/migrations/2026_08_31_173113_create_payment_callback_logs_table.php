<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_callback_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('ps_callback_id');
            $table->string('payment_system');
            $table->uuid('order_id')->nullable()->index();
            $table->string('payment_status');
            $table->decimal('amount', 12, 2);
            $table->string('currency');
            $table->timestamp('ps_created_at');
            $table->timestamp('processed_at')->nullable();
            $table->jsonb('payload');
            $table->timestamps();

            $table->unique(['ps_callback_id', 'payment_system']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_callback_logs');
    }
};
