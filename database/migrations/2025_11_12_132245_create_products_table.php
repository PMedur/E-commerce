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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku')->unique();
            $table->string('main_img')->nullable();
            $table->json('picture_galery')->nullable();
            $table->unsignedInteger('stock_status')->default(0);
            $table->Decimal('purchase_price_eur', 10, 2)->default(0.00);
            $table->Decimal('selling_price_eur', 10, 2);
            $table->foreignId('tax_rate_id')->constrained()->restrictOnDelete();
            $table->Decimal('discount_percentage', 5, 2)->default(0.00);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
