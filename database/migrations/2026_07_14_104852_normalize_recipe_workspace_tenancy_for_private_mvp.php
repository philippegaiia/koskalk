<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $recipes = DB::table('recipes')
            ->select(['id', 'owner_type', 'owner_id', 'workspace_id', 'created_by'])
            ->orderBy('id')
            ->get();
        $workspaceResolution = [];

        foreach ($recipes as $recipe) {
            $workspaceResolution[$recipe->id] = $this->resolveWorkspace($recipe);
        }

        DB::transaction(function () use ($recipes, $workspaceResolution): void {
            $createdWorkspaces = [];

            foreach ($recipes as $recipe) {
                $resolution = $workspaceResolution[$recipe->id];
                $workspaceId = $resolution['workspace_id'];

                if ($workspaceId === null) {
                    $ownerUserId = $resolution['owner_user_id'];
                    $workspaceId = $createdWorkspaces[$ownerUserId]
                        ??= $this->createOwnerWorkspace($ownerUserId);
                }

                $ownerUserId = (int) DB::table('workspaces')
                    ->where('id', $workspaceId)
                    ->value('owner_user_id');
                $createdBy = $recipe->created_by !== null
                    ? (int) $recipe->created_by
                    : $ownerUserId;
                $ownership = [
                    'owner_type' => 'workspace',
                    'owner_id' => $workspaceId,
                    'workspace_id' => $workspaceId,
                    'visibility' => 'workspace',
                    'updated_at' => now(),
                ];

                DB::table('recipes')->where('id', $recipe->id)->update($ownership + [
                    'created_by' => $createdBy,
                ]);

                $versionIds = DB::table('recipe_versions')
                    ->where('recipe_id', $recipe->id)
                    ->pluck('id');

                DB::table('recipe_versions')
                    ->where('recipe_id', $recipe->id)
                    ->update($ownership);

                if ($versionIds->isNotEmpty()) {
                    DB::table('recipe_phases')
                        ->whereIn('recipe_version_id', $versionIds)
                        ->update($ownership);
                    DB::table('recipe_items')
                        ->whereIn('recipe_version_id', $versionIds)
                        ->update($ownership);
                }

                DB::table('workspace_members')->updateOrInsert(
                    ['workspace_id' => $workspaceId, 'user_id' => $ownerUserId],
                    ['role' => 'owner', 'updated_at' => now(), 'created_at' => now()],
                );
            }
        });
    }

    public function down(): void
    {
        // Formula ownership normalization is intentionally irreversible.
    }

    /**
     * @return array{workspace_id: int|null, owner_user_id: int}
     */
    private function resolveWorkspace(object $recipe): array
    {
        $workspaceId = $recipe->workspace_id !== null
            ? (int) $recipe->workspace_id
            : ($recipe->owner_type === 'workspace' ? (int) $recipe->owner_id : null);

        if ($workspaceId !== null) {
            $ownerUserId = DB::table('workspaces')
                ->where('id', $workspaceId)
                ->value('owner_user_id');

            if ($ownerUserId === null) {
                throw new RuntimeException("Recipe {$recipe->id} references missing workspace {$workspaceId}.");
            }

            return ['workspace_id' => $workspaceId, 'owner_user_id' => (int) $ownerUserId];
        }

        if ($recipe->owner_type !== 'user' || $recipe->owner_id === null) {
            throw new RuntimeException("Recipe {$recipe->id} has ambiguous ownership and cannot be migrated safely.");
        }

        $ownerUserId = (int) $recipe->owner_id;

        if (! DB::table('users')->where('id', $ownerUserId)->exists()) {
            throw new RuntimeException("Recipe {$recipe->id} references missing user {$ownerUserId}.");
        }

        $workspaceIds = DB::table('workspaces')
            ->where('owner_user_id', $ownerUserId)
            ->orderBy('id')
            ->pluck('id');

        if ($workspaceIds->count() > 1) {
            throw new RuntimeException("Recipe {$recipe->id} owner {$ownerUserId} has multiple workspaces; set workspace_id before migrating.");
        }

        return [
            'workspace_id' => $workspaceIds->isEmpty() ? null : (int) $workspaceIds->first(),
            'owner_user_id' => $ownerUserId,
        ];
    }

    private function createOwnerWorkspace(int $ownerUserId): int
    {
        $userName = (string) DB::table('users')->where('id', $ownerUserId)->value('name');
        $name = explode(' ', trim($userName !== '' ? $userName : 'My Company'))[0]."'s Company";
        $slugBase = Str::slug($name) ?: 'company';

        do {
            $slug = $slugBase.'-'.Str::lower(Str::random(8));
        } while (DB::table('workspaces')->where('slug', $slug)->exists());

        return (int) DB::table('workspaces')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'owner_user_id' => $ownerUserId,
            'name' => $name,
            'slug' => $slug,
            'default_currency' => 'EUR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
