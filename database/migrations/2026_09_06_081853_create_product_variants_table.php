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
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('attributes')->nullable();
            $table->string('sku');
            $table->string('barcode')->nullable();
            $table->decimal('purchase_price', 12, 2)->default(0);
            $table->decimal('wholesale_price', 12, 2)->default(0);
            $table->decimal('selling_price', 12, 2);
            $table->decimal('min_selling_price', 12, 2)->default(0);
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'sku']);
            $table->unique(['branch_id', 'sku']);
            $table->index(['product_id', 'barcode']);
        });

        foreach (['branch_stocks', 'stock_movements', 'stock_transfer_items', 'purchase_items', 'sale_items', 'sale_return_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreign('variant_id')->references('id')->on('product_variants')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['branch_stocks', 'stock_movements', 'stock_transfer_items', 'purchase_items', 'sale_items', 'sale_return_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['variant_id']);
            });
        }

        Schema::dropIfExists('product_variants');
    }
};
