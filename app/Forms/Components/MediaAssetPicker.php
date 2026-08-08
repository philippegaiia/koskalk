<?php

namespace App\Forms\Components;

use App\Enums\MediaAssetType;
use App\Enums\WorkspaceMemberRole;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\EntitlementService;
use Filament\Forms\Components\Concerns\HasFileAttachments;
use Filament\Forms\Components\Field;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

class MediaAssetPicker extends Field
{
    use HasFileAttachments;

    protected string $view = 'forms.components.media-asset-picker';

    protected bool $isMultiple = false;

    protected int $maximumItems = 1;

    protected bool $shouldPreserveAspectRatio = false;

    protected bool $isEmbedded = false;

    /** @var array<int, MediaAssetType> */
    protected array $acceptedMediaAssetTypes = [MediaAssetType::Image];

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->live()
            ->fileAttachments(true)
            ->fileAttachmentsAcceptedFileTypes([
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/heic',
                'image/heif',
                'image/heic-sequence',
                'image/heif-sequence',
                'application/octet-stream',
            ])
            ->fileAttachmentsMaxSize((int) config('media.asset_uploads.max_size_kb', 10240));
    }

    public function multiple(bool $condition = true): static
    {
        $this->isMultiple = $condition;

        if ($condition && $this->getDefaultState() === null) {
            $this->default([]);
        }

        return $this;
    }

    public function maxItems(int $maximumItems): static
    {
        $this->maximumItems = max(1, $maximumItems);

        return $this;
    }

    public function isMultiple(): bool
    {
        return $this->isMultiple;
    }

    public function getMaximumItems(): int
    {
        return $this->maximumItems;
    }

    public function preserveAspectRatio(bool $condition = true): static
    {
        $this->shouldPreserveAspectRatio = $condition;

        return $this;
    }

    public function shouldPreserveAspectRatio(): bool
    {
        return $this->shouldPreserveAspectRatio;
    }

    public function embedded(bool $condition = true): static
    {
        $this->isEmbedded = $condition;

        return $this;
    }

    public function isEmbedded(): bool
    {
        return $this->isEmbedded;
    }

    public function documents(): static
    {
        $this->acceptedMediaAssetTypes = [MediaAssetType::Pdf];
        $this->fileAttachmentsAcceptedFileTypes(['application/pdf']);

        return $this;
    }

    /**
     * @return array<int, MediaAssetType>
     */
    public function getAcceptedMediaAssetTypes(): array
    {
        return $this->acceptedMediaAssetTypes;
    }

    /**
     * @return array<int, string>
     */
    public function getAcceptedMediaAssetTypeValues(): array
    {
        return array_map(
            fn (MediaAssetType $type): string => $type->value,
            $this->acceptedMediaAssetTypes,
        );
    }

    public function acceptsDocuments(): bool
    {
        return in_array(MediaAssetType::Pdf, $this->acceptedMediaAssetTypes, true);
    }

    /**
     * @return Collection<int, MediaAsset>
     */
    public function getSelectedMediaAssets(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return new Collection;
        }

        $workspace = $user->company();

        if ($workspace === null) {
            return new Collection;
        }

        $selectedIds = collect(Arr::wrap($this->getState()))
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($selectedIds->isEmpty()) {
            return new Collection;
        }

        $cacheKey = 'media-asset-picker.selected.'.$workspace->id.'.'.$selectedIds->sort()->implode(',');
        $request = request();
        $cachedAssets = $request->attributes->get($cacheKey);

        if ($cachedAssets instanceof Collection) {
            return $cachedAssets;
        }

        $assets = MediaAsset::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($selectedIds)
            ->whereIn('type', $this->getAcceptedMediaAssetTypeValues())
            ->with('media')
            ->select(['id', 'public_id', 'display_name', 'original_filename', 'status', 'type'])
            ->get();

        $request->attributes->set($cacheKey, $assets);

        return $assets;
    }

    /**
     * @return array{used: int, limit: int|null, remaining: int|null, allowed: bool}
     */
    public function getMediaAssetUsage(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return ['used' => 0, 'limit' => null, 'remaining' => null, 'allowed' => false];
        }

        $cacheKey = 'media-asset-picker.usage.'.$user->id;

        return request()->attributes->get($cacheKey)
            ?? tap(app(EntitlementService::class)->mediaAssetUsageFor($user), fn (array $usage) => request()->attributes->set($cacheKey, $usage));
    }

    public function canUpload(): bool
    {
        $user = auth()->user();
        $workspace = $user instanceof User ? $user->company() : null;

        $role = $user instanceof User && $workspace !== null
            ? request()->attributes->get('media-asset-picker.role.'.$workspace->id.'.'.$user->id)
                ?? tap($workspace->roleFor($user), fn ($role) => request()->attributes->set('media-asset-picker.role.'.$workspace->id.'.'.$user->id, $role))
            : null;

        return $user instanceof User
            && $workspace !== null
            && in_array($role, [
                WorkspaceMemberRole::Owner,
                WorkspaceMemberRole::Admin,
                WorkspaceMemberRole::Editor,
            ], true)
            && $this->getMediaAssetUsage()['allowed'];
    }
}
