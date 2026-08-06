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
        Schema::table('production_tasks', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('employee_id')->constrained('departments')->nullOnDelete();
            $table->index(['workspace_id', 'department_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_tasks', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropIndex(['workspace_id', 'department_id']);
            $table->dropColumn('department_id');
        });
    }
};
