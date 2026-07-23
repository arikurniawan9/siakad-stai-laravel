<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('graduation_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name', 180);
            $table->dateTime('registration_starts_at');
            $table->dateTime('registration_ends_at');
            $table->date('judicium_on');
            $table->date('ceremony_on')->nullable();
            $table->unsignedInteger('quota')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('graduation_applications', function (Blueprint $table): void {
            $table->id();
            $table->string('application_number', 50)->unique();
            $table->foreignId('graduation_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'graduated'])->default('draft')->index();
            $table->json('eligibility_snapshot')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('graduated_at')->nullable();
            $table->timestamps();
            $table->unique(['graduation_period_id', 'student_id'], 'graduation_application_period_student_unique');
            $table->index(['graduation_period_id', 'status']);
        });

        Schema::create('graduation_application_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('graduation_application_id');
            $table->enum('document_type', ['identity', 'photo', 'clearance'])->index();
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
            $table->unique(['graduation_application_id', 'document_type', 'version'], 'graduation_application_document_version_unique');
            $table->foreign('graduation_application_id', 'grad_app_doc_application_fk')->references('id')->on('graduation_applications')->cascadeOnDelete();
        });

        Schema::create('graduate_document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->enum('document_type', ['diploma', 'final_transcript', 'skpi']);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['year', 'document_type']);
        });

        Schema::create('graduate_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('graduation_application_id')->constrained()->cascadeOnDelete();
            $table->enum('document_type', ['diploma', 'final_transcript', 'skpi'])->index();
            $table->string('document_number', 80)->unique();
            $table->string('verification_code', 32)->unique();
            $table->json('snapshot');
            $table->string('content_hash', 64);
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
            $table->unique(['graduation_application_id', 'document_type'], 'graduate_application_document_type_unique');
        });

        Schema::create('alumni_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('graduation_application_id')->unique()->constrained()->restrictOnDelete();
            $table->string('alumni_number', 60)->unique();
            $table->string('personal_email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->enum('employment_status', ['employed', 'entrepreneur', 'studying', 'seeking', 'other'])->nullable()->index();
            $table->string('company_name', 180)->nullable();
            $table->string('position', 180)->nullable();
            $table->string('industry', 180)->nullable();
            $table->date('employment_started_on')->nullable();
            $table->boolean('directory_consent')->default(false);
            $table->timestamps();
        });

        Schema::create('tracer_study_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alumni_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('survey_year');
            $table->enum('employment_status', ['employed', 'entrepreneur', 'studying', 'seeking', 'other']);
            $table->unsignedSmallInteger('waiting_months')->nullable();
            $table->string('company_name', 180)->nullable();
            $table->string('position', 180)->nullable();
            $table->string('salary_range', 80)->nullable();
            $table->unsignedTinyInteger('study_relevance')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();
            $table->unique(['alumni_profile_id', 'survey_year'], 'tracer_profile_year_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracer_study_responses');
        Schema::dropIfExists('alumni_profiles');
        Schema::dropIfExists('graduate_documents');
        Schema::dropIfExists('graduate_document_sequences');
        Schema::dropIfExists('graduation_application_documents');
        Schema::dropIfExists('graduation_applications');
        Schema::dropIfExists('graduation_periods');
    }
};
