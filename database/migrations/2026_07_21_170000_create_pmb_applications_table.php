<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pmb_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->string('registration_number', 40)->unique();
            $table->string('full_name');
            $table->string('email')->index();
            $table->string('phone', 30);
            $table->enum('status', ['draft', 'submitted', 'verified', 'accepted', 'rejected'])->default('submitted')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pmb_applications');
    }
};
