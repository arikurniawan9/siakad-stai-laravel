<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_invigilators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lecturer_id')->constrained()->restrictOnDelete();
            $table->enum('role', ['coordinator', 'member'])->default('member');
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['exam_schedule_id', 'lecturer_id']);
            $table->index(['lecturer_id', 'exam_schedule_id']);
        });

        Schema::create('exam_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('participant_number', 40);
            $table->string('student_nim', 40);
            $table->string('student_name', 180);
            $table->boolean('is_eligible')->default(false)->index();
            $table->json('eligibility_snapshot');
            $table->enum('attendance_status', ['unmarked', 'present', 'absent', 'sick', 'excused'])->default('unmarked')->index();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();
            $table->unique(['exam_schedule_id', 'course_enrollment_id'], 'exam_participant_enrollment_unique');
            $table->unique(['exam_schedule_id', 'student_id'], 'exam_participant_student_unique');
            $table->unique(['exam_schedule_id', 'participant_number'], 'exam_participant_number_unique');
        });

        Schema::create('exam_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_schedule_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('status', ['draft', 'finalized'])->default('draft')->index();
            $table->dateTime('actual_starts_at')->nullable();
            $table->dateTime('actual_ends_at')->nullable();
            $table->text('material_summary');
            $table->text('incidents')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('participant_count')->default(0);
            $table->unsignedInteger('present_count')->default(0);
            $table->unsignedInteger('absent_count')->default(0);
            $table->unsignedInteger('sick_count')->default(0);
            $table->unsignedInteger('excused_count')->default(0);
            $table->string('verification_code', 32)->unique();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_reports');
        Schema::dropIfExists('exam_participants');
        Schema::dropIfExists('exam_invigilators');
    }
};
