<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('supported_locales', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name');
            $table->string('native_name');
            $table->string('number_locale', 32);
            $table->string('text_direction', 3)->default('ltr');
            $table->boolean('is_active')->default(false);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(10);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supported_locales');
    }
};
