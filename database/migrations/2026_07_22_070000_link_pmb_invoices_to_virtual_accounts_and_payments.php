<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_virtual_accounts', function (Blueprint $table) {
            $table->foreignId('pmb_application_id')->nullable()->after('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pmb_invoice_id')->nullable()->unique()->after('pmb_application_id')->constrained()->cascadeOnDelete();
            $table->unique('pmb_application_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('pmb_application_id')->nullable()->after('student_id')->constrained()->nullOnDelete();
            $table->foreignId('pmb_invoice_id')->nullable()->after('pmb_application_id')->constrained()->nullOnDelete();
            $table->index(['pmb_invoice_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['pmb_invoice_id', 'status']);
            $table->dropConstrainedForeignId('pmb_invoice_id');
            $table->dropConstrainedForeignId('pmb_application_id');
        });

        Schema::table('payment_virtual_accounts', function (Blueprint $table) {
            $table->dropUnique(['pmb_application_id']);
            $table->dropConstrainedForeignId('pmb_invoice_id');
            $table->dropConstrainedForeignId('pmb_application_id');
        });
    }
};
