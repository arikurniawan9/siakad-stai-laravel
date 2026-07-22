<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('guidance_availability_slots', function (Blueprint $table) {
            $table->id(); $table->foreignId('lecturer_id')->constrained('lecturers')->cascadeOnDelete(); $table->unsignedTinyInteger('weekday'); $table->time('starts_at'); $table->time('ends_at'); $table->string('mode', 20)->default('online'); $table->boolean('is_active')->default(true); $table->timestamps();
            $table->index(['lecturer_id', 'weekday', 'is_active'], 'guidance_availability_lookup_idx');
        });
        Schema::create('student_intervention_plans', function (Blueprint $table) {
            $table->id(); $table->foreignId('student_id')->constrained()->cascadeOnDelete(); $table->foreignId('warning_id')->nullable()->constrained('student_early_warnings')->nullOnDelete(); $table->foreignId('assigned_lecturer_id')->nullable()->constrained('lecturers')->nullOnDelete(); $table->foreignId('created_by')->constrained('users')->restrictOnDelete(); $table->string('title'); $table->text('action_plan'); $table->date('due_on')->nullable(); $table->string('status', 20)->default('open'); $table->text('outcome')->nullable(); $table->timestamps();
            $table->index(['student_id', 'status'], 'intervention_student_status_idx');
        });
    }
    public function down(): void { Schema::dropIfExists('student_intervention_plans'); Schema::dropIfExists('guidance_availability_slots'); }
};
