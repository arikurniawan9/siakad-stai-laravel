<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('official_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_number', 80)->unique();
            $table->string('verification_code', 40)->unique();
            $table->enum('type', ['krs', 'khs', 'transcript', 'invoice', 'receipt'])->index();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->char('content_hash', 64)->index();
            $table->json('snapshot');
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at')->index();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->string('revocation_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['type', 'source_type', 'source_id'], 'official_document_source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_documents');
    }
};
