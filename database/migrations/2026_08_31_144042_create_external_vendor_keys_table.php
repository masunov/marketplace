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
        Schema::create('external_vendor_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('sku')->index();
            $table->string('key')->index();
            $table->string('vendor_name')->index();
            $table->string('status');
            $table->string('request_id')->nullable();
            $table->timestamps();

            $table->unique(['key', 'vendor_name']);
            $table->unique(['vendor_name', 'request_id']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_vendor_keys');
    }
};
