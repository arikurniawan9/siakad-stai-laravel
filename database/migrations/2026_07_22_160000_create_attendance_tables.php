<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('meeting_number');
            $table->timestamp('starts_at'); $table->timestamp('ends_at');
            $table->string('topic'); $table->text('notes')->nullable();
            $table->enum('delivery_mode', ['onsite', 'online', 'hybrid'])->default('onsite');
            $table->enum('status', ['draft', 'open', 'closed'])->default('draft')->index();
            $table->text('access_code')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('opened_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('closed_at')->nullable();
            $table->timestamps(); $table->unique(['class_group_id', 'meeting_number']); $table->index(['class_group_id', 'starts_at']);
        });
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id(); $table->foreignId('attendance_session_id')->constrained('attendance_sessions')->cascadeOnDelete();
            $table->foreignId('course_enrollment_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['unmarked', 'present', 'late', 'sick', 'excused', 'absent'])->default('unmarked')->index();
            $table->timestamp('checked_in_at')->nullable(); $table->text('notes')->nullable(); $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps(); $table->unique(['attendance_session_id', 'course_enrollment_id'], 'attendance_record_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('attendance_records'); Schema::dropIfExists('attendance_sessions'); }
};
