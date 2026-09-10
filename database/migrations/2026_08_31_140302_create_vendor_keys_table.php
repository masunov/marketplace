<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id');
            $table->foreignUuid('product_id');
            $table->string('key')->index();
            $table->string('vendor');
            $table->uuid('request_id');
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->unique('order_id');
            $table->unique(['key', 'vendor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_keys');
    }
};
