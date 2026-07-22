<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_notifications', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 60)->default('info')->index(); $table->string('title'); $table->text('message'); $table->string('link', 1000)->nullable();
            $table->json('data')->nullable(); $table->timestamp('read_at')->nullable()->index(); $table->timestamps(); $table->index(['user_id', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('system_notifications'); }
};
