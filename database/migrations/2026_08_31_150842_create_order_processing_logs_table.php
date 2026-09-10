<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_processing_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->index();
            $table->string('order_status');
            $table->string('previous_status')->nullable();
            $table->string('vendor')->nullable();
            $table->uuid('request_id')->index();
            $table->string('result')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamps();

            $table->index(['order_id', 'triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_processing_logs');
    }
};
