<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_registration_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_term_id')->unique()->constrained()->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedTinyInteger('default_max_credits')->default(24);
            $table->boolean('is_open')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('semester_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_registration_period_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('max_credits')->default(24);
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft')->index();
            $table->enum('dispensation_status', ['none', 'requested', 'approved', 'rejected'])->default('none')->index();
            $table->text('dispensation_reason')->nullable();
            $table->text('dispensation_notes')->nullable();
            $table->foreignId('dispensation_decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispensation_decided_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'academic_term_id']);
            $table->index(['academic_term_id', 'status']);
        });

        Schema::create('course_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('semester_registration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_group_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('credits');
            $table->enum('status', ['planned', 'enrolled', 'dropped'])->default('planned')->index();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('dropped_at')->nullable();
            $table->decimal('final_score', 5, 2)->nullable();
            $table->string('letter_grade', 2)->nullable();
            $table->enum('grade_status', ['draft', 'published', 'finalized'])->default('draft')->index();
            $table->timestamp('grade_published_at')->nullable();
            $table->timestamp('grade_finalized_at')->nullable();
            $table->timestamps();
            $table->unique(['semester_registration_id', 'class_group_id'], 'course_enrollments_registration_class_unique');
            $table->index(['class_group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_enrollments');
        Schema::dropIfExists('semester_registrations');
        Schema::dropIfExists('academic_registration_periods');
    }
};
