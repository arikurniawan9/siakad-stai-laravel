<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_items', function (Blueprint $table) {
            $table->string('category', 80)->default('Perkuliahan')->after('description');
            $table->text('notes')->nullable()->after('status');
            $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            $table->foreignId('waived_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->text('waiver_reason')->nullable()->after('waived_by');
            $table->timestamp('waived_at')->nullable()->after('waiver_reason');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('recorded_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable()->after('recorded_by');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('recorded_by'));
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('notes'));
        Schema::table('billing_items', function (Blueprint $table) { $table->dropConstrainedForeignId('created_by'); $table->dropConstrainedForeignId('waived_by'); $table->dropColumn(['category', 'notes', 'waiver_reason', 'waived_at']); });
    }
};
