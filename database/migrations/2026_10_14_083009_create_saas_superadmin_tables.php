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
        if (!Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('phone')->nullable();
                $table->text('address')->nullable();
                $table->string('website')->nullable();
                $table->string('status')->default('active'); // active, inactive, pending
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('subscription_packages')) {
            Schema::create('subscription_packages', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->decimal('price', 10, 2);
                $table->integer('duration_days')->default(30);
                $table->integer('max_users')->default(5);
                $table->integer('max_branches')->default(1);
                $table->integer('max_products')->default(1000);
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('subscriptions')) {
            Schema::create('subscriptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('package_id')->constrained('subscription_packages')->cascadeOnDelete();
                $table->date('start_date');
                $table->date('end_date');
                $table->string('status')->default('active'); // active, expired, cancelled
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('domain_requests')) {
            Schema::create('domain_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('domain')->unique();
                $table->string('status')->default('pending'); // pending, approved, rejected
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('subscription_transactions')) {
            Schema::create('subscription_transactions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
                $table->decimal('amount', 10, 2);
                $table->string('payment_method')->default('card');
                $table->string('transaction_id')->nullable();
                $table->string('status')->default('completed'); // pending, completed, failed
                $table->dateTime('paid_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_transactions');
        Schema::dropIfExists('domain_requests');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_packages');
        Schema::dropIfExists('companies');
    }
};
