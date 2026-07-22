<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edom_questionnaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['academic_term_id', 'is_active']);
        });

        Schema::create('edom_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edom_questionnaire_id')->constrained('edom_questionnaires')->cascadeOnDelete();
            $table->string('category', 100);
            $table->text('question');
            $table->enum('type', ['rating', 'essay'])->default('rating');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->timestamps();
        });

        Schema::create('edom_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edom_questionnaire_id')->constrained('edom_questionnaires')->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_group_id')->constrained()->restrictOnDelete();
            $table->decimal('average_score', 4, 2)->default(0);
            $table->text('suggestion')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();
            $table->unique(['edom_questionnaire_id', 'student_id', 'class_group_id'], 'edom_response_unique');
            $table->index(['class_group_id', 'average_score']);
        });

        Schema::create('edom_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edom_response_id')->constrained('edom_responses')->cascadeOnDelete();
            $table->foreignId('edom_question_id')->constrained('edom_questions')->restrictOnDelete();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('essay_answer')->nullable();
            $table->timestamps();
            $table->unique(['edom_response_id', 'edom_question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edom_answers'); Schema::dropIfExists('edom_responses'); Schema::dropIfExists('edom_questions'); Schema::dropIfExists('edom_questionnaires');
    }
};
