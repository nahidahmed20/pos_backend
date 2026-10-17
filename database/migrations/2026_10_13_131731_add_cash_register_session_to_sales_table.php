<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->foreign('cash_register_id')
                ->references('id')
                ->on('cash_registers')
                ->nullOnDelete();
            $table->foreignId('cash_register_session_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cash_register_session_id');
            $table->dropForeign(['cash_register_id']);
        });
    }
};
