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
        Schema::create('inventory_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('batch_number', 100)->nullable();
            $table->date('manufactured_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->timestamps();
            $table->foreign('variant_id')->references('id')->on('product_variants')->nullOnDelete();
            $table->index(['branch_id', 'product_id', 'variant_id', 'expiry_date'], 'inventory_batches_lot_lookup_idx');
        });

        Schema::create('sale_item_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->unique(['sale_item_id', 'inventory_batch_id'], 'sale_item_batch_alloc_unique');
        });

        Schema::create('sale_return_item_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_return_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->unique(['sale_return_item_id', 'inventory_batch_id'], 'sale_return_batch_alloc_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_return_item_batches');
        Schema::dropIfExists('sale_item_batches');
        Schema::dropIfExists('inventory_batches');
    }
};
