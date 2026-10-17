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
        if (!Schema::hasTable('todos')) {
            Schema::create('todos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->string('title');
                $table->text('description')->nullable();
                $table->date('due_date')->nullable();
                $table->string('priority')->default('medium'); // low, medium, high
                $table->string('status')->default('pending'); // pending, in_progress, completed
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('notes')) {
            Schema::create('notes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->string('title');
                $table->text('content')->nullable();
                $table->string('tag')->nullable();
                $table->string('color')->nullable();
                $table->boolean('is_pinned')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('contacts')) {
            Schema::create('contacts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('name');
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->string('role')->nullable();
                $table->string('company')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('calendar_events')) {
            Schema::create('calendar_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->string('title');
                $table->dateTime('start_time');
                $table->dateTime('end_time');
                $table->boolean('is_all_day')->default(false);
                $table->string('location')->nullable();
                $table->text('description')->nullable();
                $table->string('color')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('call_logs')) {
            Schema::create('call_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->string('contact_name');
                $table->string('phone')->nullable();
                $table->string('call_type')->default('incoming'); // incoming, outgoing, missed, video
                $table->integer('duration_seconds')->default(0);
                $table->text('notes')->nullable();
                $table->dateTime('called_at');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('chat_messages')) {
            Schema::create('chat_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('receiver_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('message')->nullable();
                $table->string('file_path')->nullable();
                $table->boolean('is_read')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('media_files')) {
            Schema::create('media_files', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->string('name');
                $table->string('path');
                $table->string('mime_type')->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->string('folder')->default('general');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('projects')) {
            Schema::create('projects', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->string('name');
                $table->text('description')->nullable();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->decimal('budget', 12, 2)->default(0);
                $table->string('status')->default('in_progress'); // not_started, in_progress, completed, on_hold
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('ecommerce_reviews')) {
            Schema::create('ecommerce_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->string('reviewer_name');
                $table->integer('rating')->default(5);
                $table->text('comment')->nullable();
                $table->string('status')->default('approved'); // pending, approved, spam
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ecommerce_reviews');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('media_files');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('call_logs');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('notes');
        Schema::dropIfExists('todos');
    }
};
