<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_registration_periods', function (Blueprint $table): void {
            $table->dateTime('changes_starts_at')->nullable()->after('ends_at');
            $table->dateTime('changes_ends_at')->nullable()->after('changes_starts_at');
            $table->boolean('is_changes_open')->default(false)->after('is_open')->index();
        });
        Schema::table('semester_registrations', function (Blueprint $table): void {
            $table->decimal('previous_gpa', 4, 2)->nullable()->after('max_credits');
            $table->enum('credit_limit_source', ['default_period', 'previous_gpa', 'reviewer'])->default('default_period')->after('previous_gpa');
        });
        Schema::create('course_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('semester_registration_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['add', 'drop'])->index();
            $table->foreignId('class_group_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_enrollment_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->enum('status', ['requested', 'approved', 'rejected', 'cancelled'])->default('requested')->index();
            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['semester_registration_id', 'status'], 'course_change_requests_registration_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_change_requests');
        Schema::table('semester_registrations', function (Blueprint $table): void {
            $table->dropColumn(['previous_gpa', 'credit_limit_source']);
        });
        Schema::table('academic_registration_periods', function (Blueprint $table): void {
            $table->dropIndex(['is_changes_open']);
            $table->dropColumn(['changes_starts_at', 'changes_ends_at', 'is_changes_open']);
        });
    }
};
