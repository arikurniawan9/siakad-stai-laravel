<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_calendar_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_term_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 180);
            $table->enum('event_type', ['academic', 'registration', 'holiday', 'announcement', 'other'])->default('academic')->index();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->text('description')->nullable();
            $table->string('location', 180)->nullable();
            $table->boolean('is_published')->default(false)->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['academic_term_id', 'starts_at']);
        });

        Schema::create('exam_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_group_id')->constrained()->restrictOnDelete();
            $table->enum('exam_type', ['uts', 'uas'])->index();
            $table->date('exam_date')->index();
            $table->time('starts_at');
            $table->time('ends_at');
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('delivery_mode', ['onsite', 'online', 'hybrid'])->default('onsite');
            $table->enum('status', ['draft', 'published', 'cancelled'])->default('draft')->index();
            $table->string('verification_code', 32)->unique();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['class_group_id', 'exam_type']);
            $table->index(['academic_term_id', 'exam_date', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_schedules');
        Schema::dropIfExists('academic_calendar_events');
    }
};
