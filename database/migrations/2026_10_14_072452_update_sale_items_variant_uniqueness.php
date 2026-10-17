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
        if (! Schema::hasIndex('sale_items', 'sale_items_product_id_index')) {
            Schema::table('sale_items', function (Blueprint $table): void {
                $table->index('product_id', 'sale_items_product_id_index');
            });
        }

        if (! Schema::hasIndex('sale_items', 'sale_items_sale_id_product_id_variant_id_unique')) {
            Schema::table('sale_items', function (Blueprint $table): void {
                $table->unique(['sale_id', 'product_id', 'variant_id']);
            });
        }

        if (Schema::hasIndex('sale_items', 'sale_items_sale_id_product_id_unique')) {
            Schema::table('sale_items', function (Blueprint $table): void {
                $table->dropUnique('sale_items_sale_id_product_id_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasIndex('sale_items', 'sale_items_sale_id_product_id_variant_id_unique')) {
            Schema::table('sale_items', function (Blueprint $table): void {
                $table->dropUnique('sale_items_sale_id_product_id_variant_id_unique');
            });
        }

        if (! Schema::hasIndex('sale_items', 'sale_items_sale_id_product_id_unique')) {
            Schema::table('sale_items', function (Blueprint $table): void {
                $table->unique(['sale_id', 'product_id']);
            });
        }

        if (Schema::hasIndex('sale_items', 'sale_items_product_id_index')) {
            Schema::table('sale_items', function (Blueprint $table): void {
                $table->dropIndex('sale_items_product_id_index');
            });
        }
    }
};
