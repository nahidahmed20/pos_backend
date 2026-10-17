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
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('return_number');
            $table->decimal('total', 12, 2);
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->string('refund_status', 30)->default('pending');
            $table->string('refund_method', 30)->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'return_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_returns');
    }
};
