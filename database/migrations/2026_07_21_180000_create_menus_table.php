<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('menus')->cascadeOnDelete();
            $table->string('key', 80)->unique();
            $table->string('label', 100);
            $table->string('href', 180)->nullable();
            $table->string('icon', 50)->nullable();
            $table->string('permission', 100)->nullable()->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('menu_role', function (Blueprint $table) {
            $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->primary(['menu_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_role');
        Schema::dropIfExists('menus');
    }
};
