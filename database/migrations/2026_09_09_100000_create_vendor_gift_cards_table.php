<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_gift_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->index();
            $table->foreignUuid('order_item_id');
            $table->foreignUuid('product_id');
            $table->string('code');
            $table->string('vendor');
            $table->uuid('request_id');
            $table->uuid('attempt_request_id')->nullable()->index();
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->unique('order_item_id');
            $table->unique('code');
        });

        Schema::table('vendor_keys', function (Blueprint $table): void {
            $table->uuid('attempt_request_id')->nullable()->after('request_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_keys', function (Blueprint $table): void {
            $table->dropColumn('attempt_request_id');
        });

        Schema::dropIfExists('vendor_gift_cards');
    }
};
