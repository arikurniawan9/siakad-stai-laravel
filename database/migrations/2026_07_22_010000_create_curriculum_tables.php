<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curricula', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->restrictOnDelete();
            $table->foreignId('effective_term_id')->nullable()->constrained('academic_terms')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('code', 30);
            $table->unsignedSmallInteger('target_credits')->default(144);
            $table->boolean('is_active')->default(false)->index();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['program_id', 'code']);
        });

        Schema::create('curriculum_courses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('curriculum_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('semester');
            $table->unsignedTinyInteger('credits');
            $table->boolean('is_required')->default(true)->index();
            $table->timestamps();
            $table->unique(['curriculum_id', 'course_id']);
            $table->index(['curriculum_id', 'semester']);
        });

        Schema::create('course_prerequisites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prerequisite_course_id')->constrained('courses')->restrictOnDelete();
            $table->string('minimum_grade', 2)->default('C');
            $table->timestamps();
            $table->unique(['course_id', 'prerequisite_course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_prerequisites');
        Schema::dropIfExists('curriculum_courses');
        Schema::dropIfExists('curricula');
    }
};
