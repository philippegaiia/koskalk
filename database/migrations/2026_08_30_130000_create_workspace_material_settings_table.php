<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_material_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('packaging_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('buffer_quantity', 20, 9);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE workspace_material_settings ADD CONSTRAINT workspace_material_settings_exact_subject_check CHECK (((ingredient_id IS NOT NULL)::int + (packaging_item_id IS NOT NULL)::int) = 1)');
            DB::statement('ALTER TABLE workspace_material_settings ADD CONSTRAINT workspace_material_settings_non_negative_buffer_check CHECK (buffer_quantity >= 0)');
        } else {
            DB::statement(<<<'SQL'
                CREATE TRIGGER workspace_material_settings_exact_subject_insert
                BEFORE INSERT ON workspace_material_settings
                WHEN ((NEW.ingredient_id IS NOT NULL) + (NEW.packaging_item_id IS NOT NULL)) != 1
                BEGIN
                    SELECT RAISE(ABORT, 'workspace material setting requires exactly one subject');
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER workspace_material_settings_exact_subject_update
                BEFORE UPDATE OF ingredient_id, packaging_item_id ON workspace_material_settings
                WHEN ((NEW.ingredient_id IS NOT NULL) + (NEW.packaging_item_id IS NOT NULL)) != 1
                BEGIN
                    SELECT RAISE(ABORT, 'workspace material setting requires exactly one subject');
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER workspace_material_settings_buffer_insert
                BEFORE INSERT ON workspace_material_settings
                WHEN NEW.buffer_quantity < 0
                BEGIN
                    SELECT RAISE(ABORT, 'workspace material buffer cannot be negative');
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER workspace_material_settings_buffer_update
                BEFORE UPDATE OF buffer_quantity ON workspace_material_settings
                WHEN NEW.buffer_quantity < 0
                BEGIN
                    SELECT RAISE(ABORT, 'workspace material buffer cannot be negative');
                END
            SQL);
        }

        DB::statement('CREATE UNIQUE INDEX workspace_material_settings_workspace_ingredient_unique ON workspace_material_settings (workspace_id, ingredient_id) WHERE ingredient_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX workspace_material_settings_workspace_packaging_unique ON workspace_material_settings (workspace_id, packaging_item_id) WHERE packaging_item_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS workspace_material_settings_exact_subject_insert');
            DB::statement('DROP TRIGGER IF EXISTS workspace_material_settings_exact_subject_update');
            DB::statement('DROP TRIGGER IF EXISTS workspace_material_settings_buffer_insert');
            DB::statement('DROP TRIGGER IF EXISTS workspace_material_settings_buffer_update');
        }

        Schema::dropIfExists('workspace_material_settings');
    }
};
