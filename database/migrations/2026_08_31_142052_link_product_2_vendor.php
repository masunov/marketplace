<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product2vendors', function (Blueprint $table) {
            $table->uuid('product_id');
            $table->uuid('vendor_id');
            $table->integer('priority')->default(0);
            $table->integer('max_attempts')->default(1);

            $table->primary(['product_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product2vendors');
    }
};
