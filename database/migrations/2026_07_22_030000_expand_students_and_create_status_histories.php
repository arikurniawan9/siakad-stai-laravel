<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->foreignId('academic_advisor_id')->nullable()->after('program_id')->constrained('lecturers')->restrictOnDelete();
            $table->foreignId('admission_term_id')->nullable()->after('academic_advisor_id')->constrained('academic_terms')->restrictOnDelete();
            $table->unsignedSmallInteger('cohort_year')->nullable()->after('nim')->index();
            $table->enum('registration_type', ['Reguler', 'Transfer', 'Pindahan'])->default('Reguler')->after('cohort_year');
            $table->enum('gender', ['L', 'P'])->nullable()->after('registration_type');
            $table->date('birth_date')->nullable()->after('gender');
            $table->string('phone', 30)->nullable()->after('birth_date');
            $table->text('address')->nullable()->after('phone');
        });

        Schema::create('student_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_term_id')->nullable()->constrained('academic_terms')->restrictOnDelete();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->date('effective_on');
            $table->text('reason');
            $table->timestamps();
            $table->index(['student_id', 'effective_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_status_histories');
        Schema::table('students', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('academic_advisor_id');
            $table->dropConstrainedForeignId('admission_term_id');
            $table->dropColumn(['cohort_year', 'registration_type', 'gender', 'birth_date', 'phone', 'address']);
        });
    }
};
