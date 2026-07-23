<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_projects', function (Blueprint $table): void {
            $table->id();
            $table->string('project_number', 50)->unique();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->enum('project_type', ['thesis', 'internship', 'community_service'])->index();
            $table->string('title', 250);
            $table->text('abstract')->nullable();
            $table->string('organization_name', 180)->nullable();
            $table->string('location', 250)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->enum('status', ['draft', 'submitted', 'revision_required', 'approved', 'rejected', 'active', 'completed'])->default('draft')->index();
            $table->json('eligibility_snapshot')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['student_id', 'project_type', 'status']);
            $table->index(['program_id', 'academic_term_id', 'status']);
        });

        Schema::create('academic_project_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_project_id')->constrained()->cascadeOnDelete();
            $table->enum('document_type', ['proposal', 'eligibility', 'supporting', 'revision', 'final_report', 'repository'])->index();
            $table->unsignedSmallInteger('version');
            $table->string('disk', 30)->default('local');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->boolean('is_current')->default(true)->index();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['academic_project_id', 'document_type', 'version'], 'academic_project_document_version_unique');
        });

        Schema::create('academic_project_lecturers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lecturer_id')->constrained()->restrictOnDelete();
            $table->enum('role', ['supervisor', 'examiner'])->index();
            $table->unsignedTinyInteger('sequence');
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamps();
            $table->unique(['academic_project_id', 'lecturer_id'], 'academic_project_lecturer_unique');
            $table->unique(['academic_project_id', 'role', 'sequence'], 'academic_project_lecturer_role_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_project_lecturers');
        Schema::dropIfExists('academic_project_documents');
        Schema::dropIfExists('academic_projects');
    }
};
