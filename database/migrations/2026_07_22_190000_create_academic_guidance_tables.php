<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('academic_guidance_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lecturer_id')->constrained('lecturers')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('mode', 30)->default('online');
            $table->string('location')->nullable();
            $table->string('agenda');
            $table->text('student_notes')->nullable();
            $table->text('lecturer_notes')->nullable();
            $table->string('status', 30)->default('pending');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->index(['lecturer_id', 'starts_at', 'status'], 'guidance_appt_lecturer_time_idx');
            $table->index(['student_id', 'starts_at'], 'guidance_appt_student_time_idx');
        });

        Schema::create('academic_guidance_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lecturer_id')->constrained('lecturers')->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('academic_guidance_appointments')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->longText('content');
            $table->date('follow_up_due')->nullable();
            $table->string('follow_up_status', 20)->default('none');
            $table->timestamps();
            $table->index(['student_id', 'created_at'], 'guidance_notes_student_created_idx');
        });

        Schema::create('student_early_warnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_lecturer_id')->nullable()->constrained('lecturers')->nullOnDelete();
            $table->string('warning_type', 40);
            $table->string('severity', 20)->default('medium');
            $table->decimal('score', 8, 2)->nullable();
            $table->json('evidence')->nullable();
            $table->string('status', 20)->default('open');
            $table->text('resolution_notes')->nullable();
            $table->dateTime('detected_at');
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'warning_type', 'status'], 'early_warning_active_unique');
            $table->index(['assigned_lecturer_id', 'status'], 'early_warning_assignee_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_early_warnings');
        Schema::dropIfExists('academic_guidance_notes');
        Schema::dropIfExists('academic_guidance_appointments');
    }
};
