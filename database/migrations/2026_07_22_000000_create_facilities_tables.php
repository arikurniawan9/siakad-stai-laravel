<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campus_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('code', 30);
            $table->unsignedTinyInteger('floor_count')->default(1);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['campus_id', 'code']);
        });

        Schema::create('rooms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('code', 30);
            $table->unsignedTinyInteger('floor')->default(1);
            $table->enum('type', ['Kelas', 'Laboratorium', 'Aula', 'Kantor', 'Perpustakaan', 'Lainnya'])->default('Kelas')->index();
            $table->unsignedInteger('capacity')->default(1);
            $table->json('facilities')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['building_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('buildings');
    }
};
