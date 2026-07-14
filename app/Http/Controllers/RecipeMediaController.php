<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Models\User;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecipeMediaController extends Controller
{
    public function show(string $recipe, string $path, Request $request): StreamedResponse
    {
        $recipe = Recipe::withoutGlobalScopes()
            ->where('public_id', $recipe)
            ->firstOrFail();
        $user = $request->user();

        abort_unless($user instanceof User && $user->can('view', $recipe), 404);

        $normalizedPath = ltrim($path, '/');

        abort_unless(
            MediaStorage::isRecipePath($recipe, $normalizedPath)
            && $recipe->mediaPaths()->contains($normalizedPath),
            404,
        );

        $disk = Storage::disk(MediaStorage::recipeDisk());

        abort_unless($disk->exists($normalizedPath), 404);

        return $disk->response($normalizedPath, null, [
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
