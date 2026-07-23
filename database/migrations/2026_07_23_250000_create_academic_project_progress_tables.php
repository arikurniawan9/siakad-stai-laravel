<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_project_logbooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_project_id')->constrained()->cascadeOnDelete();
            $table->date('activity_on')->index();
            $table->decimal('hours', 5, 2)->nullable();
            $table->text('activity');
            $table->text('progress');
            $table->text('obstacles')->nullable();
            $table->text('next_plan')->nullable();
            $table->enum('status', ['submitted', 'verified', 'revision_required'])->default('submitted')->index();
            $table->text('supervisor_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['academic_project_id', 'activity_on']);
        });

        Schema::create('academic_project_guidance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lecturer_id')->constrained()->restrictOnDelete();
            $table->dateTime('occurred_at')->index();
            $table->enum('mode', ['onsite', 'online', 'phone'])->default('onsite');
            $table->text('discussion');
            $table->text('feedback');
            $table->text('follow_up')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['academic_project_id', 'lecturer_id', 'occurred_at'], 'project_guidance_timeline_index');
        });

        Schema::create('academic_project_defenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_project_id')->constrained()->cascadeOnDelete();
            $table->enum('defense_type', ['proposal_seminar', 'final_seminar', 'defense'])->index();
            $table->dateTime('scheduled_at')->index();
            $table->dateTime('ends_at');
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('delivery_mode', ['onsite', 'online', 'hybrid'])->default('onsite');
            $table->enum('status', ['scheduled', 'completed', 'cancelled'])->default('scheduled')->index();
            $table->string('verification_code', 32)->unique();
            $table->text('minutes_summary')->nullable();
            $table->text('incidents')->nullable();
            $table->enum('result', ['passed', 'revision', 'failed'])->nullable()->index();
            $table->decimal('final_score', 5, 2)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['academic_project_id', 'defense_type'], 'project_defense_type_unique');
            $table->index(['room_id', 'scheduled_at', 'ends_at']);
        });

        Schema::create('academic_project_rubric_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_project_defense_id');
            $table->string('name', 180);
            $table->decimal('weight', 5, 2);
            $table->decimal('max_score', 6, 2)->default(100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['academic_project_defense_id', 'name'], 'academic_project_rubric_name_unique');
            $table->foreign('academic_project_defense_id', 'project_rubric_defense_fk')->references('id')->on('academic_project_defenses')->cascadeOnDelete();
        });

        Schema::create('academic_project_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_project_defense_id');
            $table->foreignId('academic_project_rubric_item_id');
            $table->foreignId('lecturer_id')->constrained()->restrictOnDelete();
            $table->decimal('score', 6, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['academic_project_rubric_item_id', 'lecturer_id'], 'academic_project_score_examiner_unique');
            $table->index(['academic_project_defense_id', 'lecturer_id'], 'project_score_defense_lecturer_index');
            $table->foreign('academic_project_defense_id', 'project_score_defense_fk')->references('id')->on('academic_project_defenses')->cascadeOnDelete();
            $table->foreign('academic_project_rubric_item_id', 'project_score_rubric_fk')->references('id')->on('academic_project_rubric_items')->cascadeOnDelete();
        });

        Schema::create('academic_project_repositories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_project_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('final_document_id')->constrained('academic_project_documents')->restrictOnDelete();
            $table->string('title', 250);
            $table->text('abstract');
            $table->json('keywords');
            $table->boolean('publication_consent')->default(false);
            $table->string('verification_code', 32)->unique();
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_project_repositories');
        Schema::dropIfExists('academic_project_scores');
        Schema::dropIfExists('academic_project_rubric_items');
        Schema::dropIfExists('academic_project_defenses');
        Schema::dropIfExists('academic_project_guidance_records');
        Schema::dropIfExists('academic_project_logbooks');
    }
};
