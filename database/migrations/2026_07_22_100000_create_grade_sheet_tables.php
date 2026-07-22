<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_sheets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('class_group_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('status', ['draft', 'published', 'finalized'])->default('draft')->index();
            $table->text('notes')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('grade_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('grade_sheet_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('weight', 5, 2);
            $table->decimal('max_score', 7, 2)->default(100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['grade_sheet_id', 'name'], 'grade_components_sheet_name_unique');
        });

        Schema::create('student_grade_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_component_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 7, 2);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['course_enrollment_id', 'grade_component_id'], 'student_grade_scores_enrollment_component_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_grade_scores');
        Schema::dropIfExists('grade_components');
        Schema::dropIfExists('grade_sheets');
    }
};
