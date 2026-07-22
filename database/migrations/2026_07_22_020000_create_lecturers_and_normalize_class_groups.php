<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lecturers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('program_id')->constrained('programs')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('nidn', 30)->unique();
            $table->string('employee_number', 50)->nullable()->unique();
            $table->string('academic_title', 80)->nullable();
            $table->enum('employment_status', ['Tetap', 'Tidak Tetap'])->default('Tetap')->index();
            $table->string('expertise', 160)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('class_groups', function (Blueprint $table): void {
            $table->foreignId('lecturer_id')->nullable()->after('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->after('lecturer_id')->constrained()->restrictOnDelete();
            $table->softDeletes();
            $table->index(['academic_term_id', 'day', 'starts_at', 'ends_at'], 'class_groups_schedule_index');
        });
    }

    public function down(): void
    {
        Schema::table('class_groups', function (Blueprint $table): void {
            $table->dropIndex('class_groups_schedule_index');
            $table->dropConstrainedForeignId('room_id');
            $table->dropConstrainedForeignId('lecturer_id');
            $table->dropSoftDeletes();
        });
        Schema::dropIfExists('lecturers');
    }
};
