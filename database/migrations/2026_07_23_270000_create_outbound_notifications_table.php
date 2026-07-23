<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('channel', ['in_app', 'email', 'whatsapp'])->index();
            $table->string('event_key', 160);
            $table->string('event_type', 80)->index();
            $table->string('recipient')->nullable();
            $table->string('subject')->nullable();
            $table->text('content');
            $table->json('payload')->nullable();
            $table->enum('status', ['pending', 'processing', 'sent', 'failed', 'skipped'])->default('pending')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['channel', 'event_key'], 'outbound_channel_event_unique');
            $table->index(['status', 'available_at'], 'outbound_status_available_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_notifications');
    }
};
