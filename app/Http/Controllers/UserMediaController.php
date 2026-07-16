<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserMediaController extends Controller
{
    public function ingredient(string $ingredient, string $path, Request $request): StreamedResponse
    {
        $ingredient = Ingredient::query()->where('public_id', $ingredient)->firstOrFail();
        $user = $request->user();
        $normalizedPath = ltrim($path, '/');

        abort_unless(
            $user instanceof User
            && $ingredient->isAccessibleBy($user)
            && MediaStorage::isIngredientPath($ingredient, $normalizedPath)
            && in_array($normalizedPath, [
                $ingredient->featured_image_path,
                $ingredient->icon_image_path,
            ], true),
            404,
        );

        return $this->privateResponse($normalizedPath);
    }

    public function packagingItem(string $packagingItem, string $path, Request $request): StreamedResponse
    {
        $packagingItem = UserPackagingItem::query()->where('public_id', $packagingItem)->firstOrFail();
        $user = $request->user();
        $normalizedPath = ltrim($path, '/');

        abort_unless(
            $user instanceof User
            && $packagingItem->user_id === $user->id
            && MediaStorage::isPackagingItemPath($packagingItem, $normalizedPath)
            && $normalizedPath === $packagingItem->featured_image_path,
            404,
        );

        return $this->privateResponse($normalizedPath);
    }

    private function privateResponse(string $path): StreamedResponse
    {
        $disk = Storage::disk(MediaStorage::userDisk());

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
