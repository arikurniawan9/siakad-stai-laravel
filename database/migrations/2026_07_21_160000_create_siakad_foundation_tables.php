<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campuses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('code', 30)->unique();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('faculties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code', 30)->unique();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code', 30)->unique();
            $table->string('degree', 20)->default('S1');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('academic_terms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 30)->unique();
            $table->enum('semester', ['Ganjil', 'Genap', 'Pendek'])->default('Ganjil');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->unsignedTinyInteger('credits')->default(3);
            $table->enum('type', ['Wajib', 'Pilihan'])->default('Wajib');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('class_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('capacity')->default(40);
            $table->unsignedInteger('enrolled_count')->default(0);
            $table->string('room')->nullable();
            $table->string('day')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['academic_term_id', 'course_id', 'name']);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->string('nim', 30)->unique()->nullable();
            $table->enum('status', ['Aktif', 'Cuti', 'Lulus', 'Nonaktif'])->default('Aktif')->index();
            $table->unsignedTinyInteger('current_semester')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('billing_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('academic_term_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_number', 50)->unique();
            $table->string('description');
            $table->decimal('amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->date('due_on')->nullable()->index();
            $table->enum('status', ['unpaid', 'partial', 'paid', 'waived'])->default('unpaid')->index();
            $table->timestamps();
        });

        Schema::create('payment_virtual_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 30)->default('bsi');
            $table->string('va_number', 40)->unique();
            $table->string('external_reference')->nullable()->unique();
            $table->enum('status', ['pending', 'active', 'inactive', 'expired'])->default('pending')->index();
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('bank_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('event_id', 100);
            $table->string('event_type', 100)->nullable();
            $table->string('status', 30)->default('received')->index();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_webhook_event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 30)->default('bsi');
            $table->string('external_reference', 100)->unique();
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3)->default('IDR');
            $table->timestamp('paid_at')->nullable();
            $table->enum('status', ['received', 'allocated', 'partial', 'failed', 'reversed'])->default('received')->index();
            $table->timestamps();
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->unique(['payment_id', 'billing_item_id']);
        });

        Schema::create('deposit_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->string('entry_type', 30)->default('credit');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('module', 60);
            $table->string('action', 60);
            $table->string('record_type', 100)->nullable();
            $table->string('record_id', 100)->nullable();
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['module', 'action']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'deposit_ledger_entries', 'payment_allocations', 'payments', 'bank_webhook_events', 'payment_virtual_accounts', 'billing_items', 'students', 'class_groups', 'courses', 'academic_terms', 'programs', 'faculties', 'campuses'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
