<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pmb_applications', function (Blueprint $table) {
            $table->string('registration_path', 30)->default('Reguler')->after('program_id');
            $table->string('registration_type', 30)->default('Baru')->after('registration_path');
            $table->string('identity_number', 20)->nullable()->unique()->after('phone');
            $table->string('birth_place', 100)->nullable()->after('identity_number');
            $table->date('birth_date')->nullable()->after('birth_place');
            $table->enum('gender', ['L', 'P'])->nullable()->after('birth_date');
            $table->text('address')->nullable()->after('gender');
            $table->string('previous_school')->nullable()->after('address');
            $table->unsignedSmallInteger('graduation_year')->nullable()->after('previous_school');
            $table->string('guardian_name')->nullable()->after('graduation_year');
            $table->string('guardian_phone', 30)->nullable()->after('guardian_name');
            $table->timestamp('profile_completed_at')->nullable()->after('submitted_at');
        });

        Schema::create('pmb_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pmb_application_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('disk', 30)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->enum('status', ['pending', 'verified', 'rejected'])->default('pending')->index();
            $table->text('notes')->nullable();
            $table->timestamp('uploaded_at');
            $table->timestamps();
            $table->unique(['pmb_application_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pmb_documents');
        Schema::table('pmb_applications', function (Blueprint $table) {
            $table->dropUnique(['identity_number']);
            $table->dropColumn([
                'registration_path', 'registration_type', 'identity_number', 'birth_place', 'birth_date', 'gender',
                'address', 'previous_school', 'graduation_year', 'guardian_name', 'guardian_phone', 'profile_completed_at',
            ]);
        });
    }
};
