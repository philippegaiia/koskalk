<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Existing workspace-authored content is intentionally disposable for this
        // format change. Platform Markdown remains canonical and is untouched.
        DB::table('workspace_ingredient_guidances')->delete();
        DB::table('ingredients')
            ->whereNotNull('workspace_id')
            ->update(['info_markdown' => null]);

        Schema::table('workspace_ingredient_guidances', function (Blueprint $table): void {
            $table->dropColumn('guidance_markdown');
            $table->text('guidance_html');
            $table->boolean('is_active')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Content discarded by up() cannot be reconstructed on rollback.
        DB::table('workspace_ingredient_guidances')->delete();

        Schema::table('workspace_ingredient_guidances', function (Blueprint $table): void {
            $table->dropColumn(['guidance_html', 'is_active']);
            $table->text('guidance_markdown');
        });
    }
};
