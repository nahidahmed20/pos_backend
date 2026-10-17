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
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('category');
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 30)->default('cash');
            $table->string('reference')->nullable();
            $table->date('spent_at');
            $table->string('receipt_path')->nullable();
            $table->string('status', 30)->default('approved');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'spent_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
