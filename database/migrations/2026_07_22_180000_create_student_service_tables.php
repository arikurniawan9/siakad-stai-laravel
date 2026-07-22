<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_request_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 150);
            $table->enum('category', ['academic', 'finance', 'general'])->default('academic')->index();
            $table->text('description')->nullable();
            $table->json('workflow');
            $table->text('requirements_text')->nullable();
            $table->string('template_subject', 200);
            $table->text('template_body');
            $table->unsignedSmallInteger('sla_business_days')->default(3);
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('requires_financial_clearance')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('student_service_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number', 70)->unique();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_request_type_id')->constrained()->restrictOnDelete();
            $table->string('subject', 200);
            $table->text('purpose');
            $table->json('details')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->enum('status', ['in_review', 'revision_required', 'rejected', 'completed', 'cancelled'])->default('in_review')->index();
            $table->string('current_stage', 30)->nullable()->index();
            $table->unsignedSmallInteger('revision_number')->default(0);
            $table->timestamp('submitted_at')->index();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('last_action_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['student_id', 'status']);
        });

        Schema::create('student_service_request_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_service_request_id');
            $table->unsignedTinyInteger('sequence');
            $table->enum('stage', ['advisor', 'program', 'finance', 'academic']);
            $table->enum('status', ['waiting', 'pending', 'approved', 'revision_required', 'rejected'])->default('waiting')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamps();
            $table->unique(['student_service_request_id', 'sequence'], 'service_request_step_sequence');
            $table->unique(['student_service_request_id', 'stage'], 'service_request_step_stage');
            $table->foreign('student_service_request_id', 'service_steps_request_fk')->references('id')->on('student_service_requests')->cascadeOnDelete();
        });

        Schema::create('student_service_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_service_request_id');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50)->index();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->string('stage', 30)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('student_service_request_id', 'service_events_request_fk')->references('id')->on('student_service_requests')->cascadeOnDelete();
        });

        Schema::create('student_service_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_service_request_id')->unique();
            $table->string('document_number', 90)->unique();
            $table->string('verification_code', 40)->unique();
            $table->char('content_hash', 64)->index();
            $table->json('snapshot');
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at')->index();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->string('revocation_reason', 500)->nullable();
            $table->timestamps();
            $table->foreign('student_service_request_id', 'service_documents_request_fk')->references('id')->on('student_service_requests')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_service_documents');
        Schema::dropIfExists('student_service_request_events');
        Schema::dropIfExists('student_service_request_steps');
        Schema::dropIfExists('student_service_requests');
        Schema::dropIfExists('service_request_types');
    }
};
