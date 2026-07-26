<?php

namespace App\Http\Controllers;

use App\MediaAssetStatus;
use App\MediaAssetType;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\MediaAssetLibraryService;
use App\Services\MediaAssetUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaAssetPickerMutationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $workspace = $user instanceof User ? $user->company() : null;

        abort_unless($user instanceof User && $workspace !== null, 404);

        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'types' => ['nullable', 'string', 'max:50'],
        ]);

        $search = mb_strtolower(trim($request->string('search')->toString()));
        $requestedTypes = collect(explode(',', $request->string('types', MediaAssetType::Image->value)->toString()))
            ->filter(fn (string $type): bool => MediaAssetType::tryFrom($type) !== null)
            ->unique()
            ->values();
        abort_if($requestedTypes->isEmpty(), 422);

        $assets = MediaAsset::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('type', $requestedTypes)
            ->with('media')
            ->select(['id', 'public_id', 'display_name', 'original_filename', 'status', 'type', 'progress', 'created_at'])
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.$search.'%';
                $query->where(function ($query) use ($term): void {
                    $query->whereRaw('LOWER(display_name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(original_filename) LIKE ?', [$term]);
                });
            })
            ->latest()
            ->simplePaginate(48);

        return response()->json([
            'data' => collect($assets->items())->map(fn (MediaAsset $asset): array => [
                'id' => $asset->id,
                'display_name' => $asset->displayName(),
                'original_filename' => $asset->original_filename,
                'status' => $asset->status->value,
                'type' => $asset->type->value,
                'progress' => $asset->progress,
                'thumbnail_url' => $asset->status === MediaAssetStatus::Ready && $asset->getFirstMedia('master') !== null
                    ? route('media.show', [$asset, 'thumbnail'])
                    : null,
                'master_url' => $asset->status === MediaAssetStatus::Ready && $asset->getFirstMedia('master') !== null
                    ? route('media.show', [$asset, 'master'])
                    : null,
                'download_url' => $asset->status === MediaAssetStatus::Ready && $asset->type === MediaAssetType::Pdf
                    ? route('media.download', $asset)
                    : null,
            ])->all(),
            'has_more' => $assets->hasMorePages(),
            'next_page' => $assets->hasMorePages() ? $assets->currentPage() + 1 : null,
        ]);
    }

    public function retry(Request $request, MediaAsset $mediaAsset, MediaAssetUploadService $uploads): JsonResponse
    {
        $user = $this->authorizedUser($request, $mediaAsset);
        $asset = $uploads->retry($user, $mediaAsset);

        return response()->json([
            'status' => $asset->status->value,
            'progress' => $asset->progress,
            'failure_reason' => $asset->failure_reason,
        ]);
    }

    public function remove(Request $request, MediaAsset $mediaAsset, MediaAssetLibraryService $library): JsonResponse
    {
        $user = $this->authorizedUser($request, $mediaAsset);
        $library->remove($user, $mediaAsset);

        return response()->json(['removed' => true]);
    }

    private function authorizedUser(Request $request, MediaAsset $mediaAsset): User
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can('view', $mediaAsset), 404);

        return $user;
    }
}
