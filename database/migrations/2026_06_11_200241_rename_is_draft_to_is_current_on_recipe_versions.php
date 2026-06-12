<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_versions', function (Blueprint $table) {
            $table->renameColumn('is_draft', 'is_current');
        });
    }

    public function down(): void
    {
        Schema::table('recipe_versions', function (Blueprint $table) {
            $table->renameColumn('is_current', 'is_draft');
        });
    }
};
