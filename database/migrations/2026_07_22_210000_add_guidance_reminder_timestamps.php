<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('academic_guidance_appointments', function (Blueprint $table) { $table->dateTime('reminder_sent_at')->nullable()->after('completed_at'); }); Schema::table('student_intervention_plans', function (Blueprint $table) { $table->dateTime('reminder_sent_at')->nullable()->after('outcome'); }); }
    public function down(): void { Schema::table('student_intervention_plans', fn (Blueprint $table) => $table->dropColumn('reminder_sent_at')); Schema::table('academic_guidance_appointments', fn (Blueprint $table) => $table->dropColumn('reminder_sent_at')); }
};
