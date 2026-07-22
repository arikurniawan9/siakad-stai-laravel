<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('external_url', 1000)->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 120)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['class_group_id', 'is_published']);
        });

        Schema::create('lms_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->timestamp('due_at');
            $table->decimal('max_points', 7, 2)->default(100);
            $table->string('external_url', 1000)->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 120)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['class_group_id', 'is_published', 'due_at']);
        });

        Schema::create('lms_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lms_assignment_id')->constrained('lms_assignments')->cascadeOnDelete();
            $table->foreignId('course_enrollment_id')->constrained()->cascadeOnDelete();
            $table->text('answer_text')->nullable();
            $table->string('external_url', 1000)->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 120)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->enum('status', ['submitted', 'graded'])->default('submitted');
            $table->timestamp('submitted_at');
            $table->decimal('score', 7, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();
            $table->unique(['lms_assignment_id', 'course_enrollment_id'], 'lms_submission_unique');
        });

        Schema::create('lms_forum_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('content');
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
            $table->index(['class_group_id', 'is_pinned']);
        });

        Schema::create('lms_forum_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lms_forum_topic_id')->constrained('lms_forum_topics')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_forum_comments');
        Schema::dropIfExists('lms_forum_topics');
        Schema::dropIfExists('lms_submissions');
        Schema::dropIfExists('lms_assignments');
        Schema::dropIfExists('lms_materials');
    }
};
