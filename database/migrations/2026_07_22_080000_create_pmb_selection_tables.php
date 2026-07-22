<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pmb_selections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->decimal('passing_grade', 5, 2);
            $table->enum('status', ['draft', 'finalized'])->default('draft')->index();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['academic_term_id', 'program_id', 'status']);
        });

        Schema::create('pmb_selection_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pmb_selection_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('weight', 5, 2);
            $table->decimal('max_score', 7, 2)->default(100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['pmb_selection_id', 'name']);
        });

        Schema::create('pmb_selection_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pmb_selection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pmb_application_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->decimal('final_score', 5, 2)->nullable();
            $table->enum('decision', ['pending', 'accepted', 'rejected'])->default('pending')->index();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->unique(['pmb_selection_id', 'pmb_application_id']);
        });

        Schema::create('pmb_selection_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pmb_selection_result_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pmb_selection_component_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 7, 2);
            $table->timestamps();
            $table->unique(['pmb_selection_result_id', 'pmb_selection_component_id'], 'pmb_result_component_unique');
        });

        Schema::create('nim_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('cohort_year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['program_id', 'cohort_year']);
        });

        Schema::table('students', function (Blueprint $table): void {
            $table->foreignId('pmb_application_id')->nullable()->unique()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pmb_application_id');
        });
        Schema::dropIfExists('nim_sequences');
        Schema::dropIfExists('pmb_selection_scores');
        Schema::dropIfExists('pmb_selection_results');
        Schema::dropIfExists('pmb_selection_components');
        Schema::dropIfExists('pmb_selections');
    }
};
