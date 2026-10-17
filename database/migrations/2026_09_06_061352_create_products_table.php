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
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('brand')->nullable();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('slug')->nullable()->unique();
            $table->string('sku');
            $table->string('barcode')->nullable();
            $table->string('secondary_barcode')->nullable();
            $table->string('unit')->default('pcs');
            $table->string('product_type')->default('standard');
            $table->string('model_number')->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->decimal('weight', 10, 3)->nullable();
            $table->decimal('length', 10, 2)->nullable();
            $table->decimal('width', 10, 2)->nullable();
            $table->decimal('height', 10, 2)->nullable();
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->decimal('purchase_price', 12, 2)->default(0);
            $table->decimal('wholesale_price', 12, 2)->default(0);
            $table->decimal('selling_price', 12, 2);
            $table->decimal('min_selling_price', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->boolean('is_sellable')->default(true);
            $table->boolean('is_purchasable')->default(true);
            $table->boolean('track_stock')->default(true);
            $table->boolean('has_expiry')->default(false);
            $table->boolean('is_serialized')->default(false);
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['branch_id', 'sku']);
            $table->index(['branch_id', 'barcode']);
            $table->index(['branch_id', 'secondary_barcode']);
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
