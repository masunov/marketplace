<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id');
            $table->uuid('request_id')->index();
            $table->decimal('price', 12);
            $table->string('currency');
            $table->string('status');
            $table->timestamp('delivery_cycle_started_at')->nullable();
            $table->string('related_content_type')->nullable();
            $table->uuid('related_content_id')->nullable();
            $table->timestamps();

            $table->index(['related_content_type', 'related_content_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
