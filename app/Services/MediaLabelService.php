<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\MediaLabel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaLabelService
{
    public const MAX_LABELS_PER_ASSET = 8;

    public const MAX_NAME_LENGTH = 30;

    public function __construct(private readonly EntitlementService $entitlements) {}

    public function create(User $user, Workspace $workspace, string $name): MediaLabel
    {
        Gate::forUser($user)->authorize('create', [MediaLabel::class, $workspace]);

        $rateLimitKey = "media-label:create:{$workspace->id}:{$user->id}";

        if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
            throw ValidationException::withMessages([
                'label' => __('media_library.labels.rate_limited', [
                    'seconds' => RateLimiter::availableIn($rateLimitKey),
                ]),
            ]);
        }

        RateLimiter::hit($rateLimitKey, 60);
        [$displayName, $normalizedName] = $this->validatedNames($name);

        return $this->entitlements->withinWorkspaceQuotaLock(
            $workspace,
            function (Workspace $lockedWorkspace) use ($displayName, $normalizedName, $user): MediaLabel {
                $this->entitlements->assertCanCreateMediaLabelInWorkspace($lockedWorkspace);

                if (MediaLabel::query()
                    ->where('workspace_id', $lockedWorkspace->id)
                    ->where('normalized_name', $normalizedName)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'label' => __('media_library.labels.duplicate'),
                    ]);
                }

                return MediaLabel::query()->create([
                    'workspace_id' => $lockedWorkspace->id,
                    'created_by_user_id' => $user->id,
                    'name' => $displayName,
                    'normalized_name' => $normalizedName,
                ]);
            },
        );
    }

    public function rename(User $user, MediaLabel $label, string $name): MediaLabel
    {
        Gate::forUser($user)->authorize('update', $label);
        [$displayName, $normalizedName] = $this->validatedNames($name);

        if (MediaLabel::query()
            ->where('workspace_id', $label->workspace_id)
            ->where('normalized_name', $normalizedName)
            ->whereKeyNot($label->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'label' => __('media_library.labels.duplicate'),
            ]);
        }

        $label->update([
            'name' => $displayName,
            'normalized_name' => $normalizedName,
        ]);

        return $label->refresh();
    }

    public function delete(User $user, MediaLabel $label): void
    {
        Gate::forUser($user)->authorize('delete', $label);
        $label->delete();
    }

    /**
     * @param  array<int, int|string>  $labelIds
     */
    public function sync(User $user, MediaAsset $asset, array $labelIds): void
    {
        Gate::forUser($user)->authorize('update', $asset);

        $ids = collect($labelIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->count() > self::MAX_LABELS_PER_ASSET) {
            throw ValidationException::withMessages([
                'labels' => __('media_library.labels.asset_limit', [
                    'count' => self::MAX_LABELS_PER_ASSET,
                ]),
            ]);
        }

        $workspaceLabelIds = MediaLabel::query()
            ->where('workspace_id', $asset->workspace_id)
            ->whereKey($ids)
            ->pluck('id');

        if ($workspaceLabelIds->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'labels' => __('media_library.labels.invalid'),
            ]);
        }

        $asset->labels()->sync($workspaceLabelIds);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function validatedNames(string $name): array
    {
        $displayName = Str::squish($name);

        if ($displayName === '' || mb_strlen($displayName) > self::MAX_NAME_LENGTH) {
            throw ValidationException::withMessages([
                'label' => __('media_library.labels.name_invalid', [
                    'count' => self::MAX_NAME_LENGTH,
                ]),
            ]);
        }

        return [$displayName, Str::lower($displayName)];
    }
}
