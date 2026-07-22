<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pmb_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->default('Biaya Pendaftaran PMB');
            $table->string('registration_path', 30)->default('Semua');
            $table->string('registration_type', 30)->default('Semua');
            $table->string('wave', 50)->nullable();
            $table->decimal('amount', 15, 2);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedTinyInteger('due_days')->default(3);
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['academic_term_id', 'is_active']);
            $table->index(['registration_path', 'registration_type', 'program_id']);
        });

        Schema::table('pmb_applications', function (Blueprint $table) {
            $table->foreignId('pmb_fee_id')->nullable()->after('program_id')->constrained('pmb_fees')->nullOnDelete();
            $table->string('registration_wave', 50)->nullable()->after('registration_type');
        });

        Schema::create('pmb_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pmb_application_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('pmb_fee_id')->nullable()->constrained('pmb_fees')->nullOnDelete();
            $table->string('invoice_number', 60)->unique();
            $table->string('description');
            $table->decimal('amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->timestamp('due_at')->nullable()->index();
            $table->enum('status', ['unpaid', 'partial', 'paid', 'waived', 'expired'])->default('unpaid')->index();
            $table->timestamp('issued_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pmb_invoices');
        Schema::table('pmb_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pmb_fee_id');
            $table->dropColumn('registration_wave');
        });
        Schema::dropIfExists('pmb_fees');
    }
};
