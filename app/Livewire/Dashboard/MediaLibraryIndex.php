<?php

namespace App\Livewire\Dashboard;

use App\Enums\MediaAssetStatus;
use App\Enums\MediaAssetType;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Models\Ingredient;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\MediaLabel;
use App\Models\PackagingItem;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\CurrentAppUserResolver;
use App\Services\EntitlementService;
use App\Services\MediaAssetLibraryService;
use App\Services\MediaAssetUploadService;
use App\Services\MediaLabelService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

class MediaLibraryIndex extends Component
{
    use InteractsWithAppNotifications;
    use WithFileUploads;
    use WithoutUrlPagination;
    use WithPagination;

    public mixed $upload = null;

    public string $search = '';

    public string $statusFilter = 'all';

    public string $usageFilter = 'all';

    public string $typeFilter = 'all';

    /** @var array<int, int|string> */
    public array $labelFilter = [];

    /** @var array<int, int|string> */
    public array $uploadLabelIds = [];

    /** @var array<int, int|string> */
    public array $selectedLabelIds = [];

    /** @var array<int, string> */
    public array $labelNames = [];

    public string $newLabelName = '';

    public ?string $statusMessage = null;

    public string $statusType = 'success';

    #[Locked]
    public ?int $selectedAssetId = null;

    public string $assetPanelTab = 'settings';

    public string $usageSearch = '';

    /** @var array<int, string> */
    public array $displayNames = [];

    public function openAssetPanel(
        int $assetId,
        string $tab = 'settings',
        ?CurrentAppUserResolver $resolver = null,
    ): void {
        $resolver ??= app(CurrentAppUserResolver::class);
        abort_unless(in_array($tab, ['settings', 'usage'], true), 404);

        $user = $resolver->resolve();
        $asset = $this->workspaceAsset($assetId, $user);

        abort_unless($user instanceof User && $asset instanceof MediaAsset, 404);

        $this->selectedAssetId = $asset->id;
        $this->assetPanelTab = $tab;
        $this->usageSearch = '';
        $this->displayNames[$asset->id] = $asset->displayName();
        $this->selectedLabelIds = $asset->labels()->pluck('media_labels.id')->all();
        $this->labelNames = MediaLabel::query()
            ->where('workspace_id', $asset->workspace_id)
            ->pluck('name', 'id')
            ->all();
    }

    public function showAssetPanelTab(string $tab): void
    {
        abort_unless(in_array($tab, ['settings', 'usage'], true), 404);

        $this->assetPanelTab = $tab;
    }

    public function closeAssetPanel(): void
    {
        $this->selectedAssetId = null;
        $this->assetPanelTab = 'settings';
        $this->usageSearch = '';
        $this->selectedLabelIds = [];
        $this->newLabelName = '';
    }

    public function beginRename(int $assetId, CurrentAppUserResolver $resolver): void
    {
        if (array_key_exists($assetId, $this->displayNames)) {
            return;
        }

        $user = $resolver->resolve();
        $asset = $this->workspaceAsset($assetId, $user);

        abort_unless($user instanceof User && $asset instanceof MediaAsset, 404);

        $this->displayNames[$assetId] = $asset->displayName();
    }

    public function rename(
        int $assetId,
        string $displayName,
        CurrentAppUserResolver $resolver,
        MediaAssetLibraryService $library,
    ): void {
        $user = $resolver->resolve();
        $asset = $this->workspaceAsset($assetId, $user);

        abort_unless($user instanceof User && $asset instanceof MediaAsset, 404);

        try {
            $library->rename($user, $asset, $displayName);
        } catch (ValidationException $exception) {
            $this->addError(
                "displayNames.{$assetId}",
                $exception->validator->errors()->first('display_name'),
            );

            return;
        }

        $this->resetErrorBag("displayNames.{$assetId}");
        $this->displayNames[$assetId] = $asset->fresh()->displayName();
        $this->showAppNotification(
            __('media_library.messages.renamed', ['name' => $this->displayNames[$assetId]]),
        );
    }

    public function renameFromInput(
        int $assetId,
        CurrentAppUserResolver $resolver,
        MediaAssetLibraryService $library,
    ): void {
        $this->rename(
            $assetId,
            $this->displayNames[$assetId] ?? '',
            $resolver,
            $library,
        );
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedUsageFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedLabelFilter(): void
    {
        $this->resetPage();
    }

    public function uploadAsset(
        CurrentAppUserResolver $resolver,
        MediaAssetUploadService $uploads,
        MediaLabelService $labels,
    ): void {
        $user = $resolver->resolve();
        $workspace = $user?->company();

        abort_unless($user instanceof User && $workspace instanceof Workspace, 403);

        $this->validate([
            'upload' => ['required', 'file', 'max:10240'],
        ]);

        $asset = $uploads->start(
            $user,
            $workspace,
            $this->upload,
            [MediaAssetType::Image, MediaAssetType::Pdf],
        );

        if ($this->uploadLabelIds !== []) {
            $labels->sync($user, $asset, $this->uploadLabelIds);
        }

        $this->reset('upload');
        $this->showAppNotification(
            __('media_library.messages.upload_processing', ['name' => $asset->original_filename]),
        );
    }

    public function createLabel(
        CurrentAppUserResolver $resolver,
        MediaLabelService $labels,
    ): void {
        $user = $resolver->resolve();
        $workspace = $user?->company();
        abort_unless($user instanceof User && $workspace instanceof Workspace, 403);

        try {
            $label = $labels->create($user, $workspace, $this->newLabelName);
        } catch (ValidationException $exception) {
            $this->addError('newLabelName', $exception->validator->errors()->first('label'));

            return;
        }

        $this->resetErrorBag('newLabelName');
        $this->newLabelName = '';
        $this->labelNames[$label->id] = $label->name;

        if ($this->selectedAssetId !== null) {
            $asset = $this->workspaceAsset($this->selectedAssetId, $user);
            abort_unless($asset instanceof MediaAsset, 404);

            $this->selectedLabelIds[] = $label->id;
            $labels->sync($user, $asset, $this->selectedLabelIds);
        }
    }

    public function assignLabel(
        int $labelId,
        CurrentAppUserResolver $resolver,
        MediaLabelService $labels,
    ): void {
        $this->syncSelectedLabels(
            [...$this->selectedLabelIds, $labelId],
            $resolver,
            $labels,
        );
    }

    public function removeSelectedLabel(
        int $labelId,
        CurrentAppUserResolver $resolver,
        MediaLabelService $labels,
    ): void {
        $this->syncSelectedLabels(
            $this->withoutId($this->selectedLabelIds, $labelId),
            $resolver,
            $labels,
        );
    }

    public function renameLabel(
        int $labelId,
        CurrentAppUserResolver $resolver,
        MediaLabelService $labels,
    ): void {
        $user = $resolver->resolve();
        $label = $this->workspaceLabel($labelId, $user);
        abort_unless($user instanceof User && $label instanceof MediaLabel, 404);

        try {
            $renamed = $labels->rename($user, $label, $this->labelNames[$labelId] ?? '');
        } catch (ValidationException $exception) {
            $this->addError("labelNames.{$labelId}", $exception->validator->errors()->first('label'));

            return;
        }

        $this->labelNames[$labelId] = $renamed->name;
        $this->resetErrorBag("labelNames.{$labelId}");
    }

    public function deleteLabel(
        int $labelId,
        CurrentAppUserResolver $resolver,
        MediaLabelService $labels,
    ): void {
        $user = $resolver->resolve();
        $label = $this->workspaceLabel($labelId, $user);
        abort_unless($user instanceof User && $label instanceof MediaLabel, 404);

        $labels->delete($user, $label);
        $this->selectedLabelIds = $this->withoutId($this->selectedLabelIds, $labelId);
        $this->labelFilter = $this->withoutId($this->labelFilter, $labelId);
        $this->uploadLabelIds = $this->withoutId($this->uploadLabelIds, $labelId);
        unset($this->labelNames[$labelId]);
    }

    public function retry(
        int $assetId,
        CurrentAppUserResolver $resolver,
        MediaAssetUploadService $uploads,
    ): void {
        $user = $resolver->resolve();
        $asset = $this->workspaceAsset($assetId, $user);

        abort_unless($user instanceof User && $asset instanceof MediaAsset, 404);

        $uploads->retry($user, $asset);
        $this->showAppNotification(
            __('media_library.messages.retry_processing', ['name' => $asset->original_filename]),
        );
    }

    public function remove(
        int $assetId,
        CurrentAppUserResolver $resolver,
        MediaAssetLibraryService $library,
    ): void {
        $user = $resolver->resolve();
        $asset = $this->workspaceAsset($assetId, $user);

        abort_unless($user instanceof User, 404);

        if (! $asset instanceof MediaAsset) {
            abort_unless($this->selectedAssetId === $assetId, 404);
            $this->closeAssetPanel();

            return;
        }

        $filename = $asset->original_filename;
        $library->remove($user, $asset);

        if ($this->selectedAssetId === $assetId) {
            $this->closeAssetPanel();
        }

        $this->showAppNotification(
            __('media_library.messages.removed', ['name' => $filename]),
        );
    }

    public function updateFocalPoint(
        int $assetId,
        float $focalX,
        float $focalY,
        CurrentAppUserResolver $resolver,
        MediaAssetLibraryService $library,
    ): void {
        $user = $resolver->resolve();
        $asset = $this->workspaceAsset($assetId, $user);

        abort_unless($user instanceof User && $asset instanceof MediaAsset, 404);

        $library->updateFocalPoint($user, $asset, $focalX, $focalY);
        $this->showAppNotification(__('media_library.messages.focal_refreshing'));
    }

    public function render(
        CurrentAppUserResolver $resolver,
        EntitlementService $entitlements,
    ): View {
        $user = $resolver->resolve();
        $workspace = $user?->company();
        $assets = $workspace instanceof Workspace
            ? $this->assets($workspace)
            : new LengthAwarePaginator([], 0, 24);
        $usage = $user instanceof User
            ? $entitlements->mediaAssetUsageFor($user)
            : ['used' => 0, 'limit' => null, 'remaining' => null, 'allowed' => false];
        $mediaRole = $user instanceof User && $workspace instanceof Workspace
            ? $this->mediaRole($user, $workspace)
            : null;
        $canUpdateMedia = in_array($mediaRole, [
            WorkspaceMemberRole::Owner,
            WorkspaceMemberRole::Admin,
            WorkspaceMemberRole::Editor,
        ], true);
        $canDeleteMedia = in_array($mediaRole, [
            WorkspaceMemberRole::Owner,
            WorkspaceMemberRole::Admin,
        ], true);
        $hasProcessingAssets = $workspace instanceof Workspace
            && MediaAsset::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', MediaAssetStatus::Processing)
                ->exists();
        $selectedAsset = $workspace instanceof Workspace
            ? $this->selectedAsset($workspace)
            : null;
        $selectedDeletionImpact = $this->deletionImpact($selectedAsset);
        $labels = $workspace instanceof Workspace
            ? MediaLabel::query()
                ->where('workspace_id', $workspace->id)
                ->orderBy('normalized_name')
                ->get()
            : collect();

        return view('livewire.dashboard.media-library-index', [
            'assets' => $assets,
            'usage' => $usage,
            'user' => $user,
            'canUpdateMedia' => $canUpdateMedia,
            'canDeleteMedia' => $canDeleteMedia,
            'hasProcessingAssets' => $hasProcessingAssets,
            'selectedAsset' => $selectedAsset,
            'selectedLogicalUsageCount' => $selectedAsset instanceof MediaAsset
                ? $this->logicalUsages($selectedAsset->usages)->count()
                : 0,
            'selectedDeletionImpact' => $selectedDeletionImpact,
            'selectedDeletionImpactLabel' => $this->deletionImpactLabel($selectedDeletionImpact),
            'selectedUsageGroups' => $this->selectedUsageGroups($selectedAsset),
            'labels' => $labels,
        ]);
    }

    private function assets(Workspace $workspace): LengthAwarePaginator
    {
        $assets = MediaAsset::query()
            ->where('workspace_id', $workspace->id)
            ->with([
                'labels',
                'media',
                'usages:id,media_asset_id,usable_type,usable_id,role',
            ])
            ->when(
                filled($this->search),
                function ($query): void {
                    $search = '%'.mb_strtolower(trim($this->search)).'%';

                    $query->where(function ($query) use ($search): void {
                        $query
                            ->whereRaw('LOWER(display_name) LIKE ?', [$search])
                            ->orWhereRaw('LOWER(original_filename) LIKE ?', [$search]);
                    });
                },
            )
            ->when(
                in_array($this->statusFilter, array_column(MediaAssetStatus::cases(), 'value'), true),
                fn ($query) => $query->where('status', $this->statusFilter),
            )
            ->when($this->usageFilter === 'used', fn ($query) => $query->has('usages'))
            ->when($this->usageFilter === 'unused', fn ($query) => $query->doesntHave('usages'))
            ->when(
                in_array($this->typeFilter, array_column(MediaAssetType::cases(), 'value'), true),
                fn ($query) => $query->where('type', $this->typeFilter),
            )
            ->when(
                $this->labelFilter !== [],
                fn ($query) => $query->whereHas(
                    'labels',
                    fn ($query) => $query->whereKey($this->labelFilter),
                ),
            )
            ->latest()
            ->paginate(24);

        $this->setLogicalUsageCounts($assets->getCollection());

        return $assets;
    }

    private function selectedAsset(Workspace $workspace): ?MediaAsset
    {
        if ($this->selectedAssetId === null) {
            return null;
        }

        return MediaAsset::query()
            ->where('workspace_id', $workspace->id)
            ->with([
                'labels',
                'media',
                'usages' => fn ($query) => $query->latest(),
                'usages.usable' => function (MorphTo $morphTo): void {
                    $morphTo->morphWith([
                        RecipeVersion::class => ['recipe'],
                    ]);
                },
            ])
            ->withCount('usages')
            ->find($this->selectedAssetId);
    }

    /**
     * @param  array<int, int|string>  $labelIds
     */
    private function syncSelectedLabels(
        array $labelIds,
        CurrentAppUserResolver $resolver,
        MediaLabelService $labels,
    ): void {
        $user = $resolver->resolve();
        $asset = $this->selectedAssetId === null
            ? null
            : $this->workspaceAsset($this->selectedAssetId, $user);
        abort_unless($user instanceof User && $asset instanceof MediaAsset, 404);

        try {
            $labels->sync($user, $asset, $labelIds);
        } catch (ValidationException $exception) {
            $this->addError('selectedLabelIds', $exception->validator->errors()->first('labels'));

            return;
        }

        $this->resetErrorBag('selectedLabelIds');
        $this->selectedLabelIds = $asset->labels()
            ->orderBy('media_asset_label.created_at')
            ->pluck('media_labels.id')
            ->all();
        $this->showAppNotification(__('media_library.messages.labels_updated'));
    }

    /**
     * @return Collection<string, Collection<int, MediaAssetUsage>>
     */
    private function selectedUsageGroups(?MediaAsset $asset): Collection
    {
        if (! $asset instanceof MediaAsset) {
            return collect();
        }

        $search = Str::lower(trim($this->usageSearch));

        return $this->logicalUsages($asset->usages)
            ->filter(function (MediaAssetUsage $usage) use ($search): bool {
                if ($search === '') {
                    return true;
                }

                return Str::contains(
                    Str::lower($this->usageTargetName($usage).' '.__('media_library.roles.'.$usage->role->value)),
                    $search,
                );
            })
            ->groupBy(fn (MediaAssetUsage $usage): string => match (true) {
                $usage->usable instanceof Recipe => 'recipes',
                $usage->usable instanceof Ingredient => 'ingredients',
                $usage->usable instanceof PackagingItem => 'packaging',
                default => 'other',
            });
    }

    /**
     * @param  Collection<int, MediaAssetUsage>  $usages
     * @return Collection<int, MediaAssetUsage>
     */
    private function logicalUsages(Collection $usages): Collection
    {
        return $usages
            ->map(function (MediaAssetUsage $usage): ?MediaAssetUsage {
                if ($usage->usable instanceof RecipeVersion) {
                    if (! $usage->usable->recipe instanceof Recipe) {
                        return null;
                    }

                    $usage = clone $usage;
                    $usage->setRelation('usable', $usage->usable->recipe);
                }

                return $usage->usable instanceof Recipe
                    || $usage->usable instanceof Ingredient
                    || $usage->usable instanceof PackagingItem
                        ? $usage
                        : null;
            })
            ->filter()
            ->unique(function (MediaAssetUsage $usage): string {
                return implode(':', [
                    $usage->usable::class,
                    $usage->usable->getKey(),
                    $usage->role->value,
                ]);
            })
            ->values();
    }

    /**
     * @param  Collection<int, MediaAsset>  $assets
     */
    private function setLogicalUsageCounts(Collection $assets): void
    {
        $recipeIdsByVersionId = RecipeVersion::withoutGlobalScopes()
            ->whereKey(
                $assets
                    ->flatMap->usages
                    ->where('usable_type', RecipeVersion::class)
                    ->pluck('usable_id'),
            )
            ->pluck('recipe_id', 'id');

        $assets->each(function (MediaAsset $asset) use ($recipeIdsByVersionId): void {
            $count = $asset->usages
                ->map(function (MediaAssetUsage $usage) use ($recipeIdsByVersionId): ?string {
                    $usableType = $usage->usable_type;
                    $usableId = $usage->usable_id;

                    if ($usableType === RecipeVersion::class) {
                        $usableType = Recipe::class;
                        $usableId = $recipeIdsByVersionId->get($usableId);
                    }

                    if (
                        $usableId === null
                        || ! in_array($usableType, [
                            Recipe::class,
                            Ingredient::class,
                            PackagingItem::class,
                        ], true)
                    ) {
                        return null;
                    }

                    return implode(':', [$usableType, $usableId, $usage->role->value]);
                })
                ->filter()
                ->unique()
                ->count();

            $asset->setAttribute('logical_usages_count', $count);
        });
    }

    private function usageTargetName(MediaAssetUsage $usage): string
    {
        return match (true) {
            $usage->usable instanceof Recipe => $usage->usable->name,
            $usage->usable instanceof Ingredient => $usage->usable->display_name,
            $usage->usable instanceof PackagingItem => $usage->usable->name,
            default => __('media_library.missing_target'),
        };
    }

    /**
     * @return array{recipes:int, other:int, total:int}
     */
    private function deletionImpact(?MediaAsset $asset): array
    {
        if (! $asset instanceof MediaAsset) {
            return ['recipes' => 0, 'other' => 0, 'total' => 0];
        }

        $targets = $asset->usages
            ->map(function (MediaAssetUsage $usage): Recipe|Ingredient|PackagingItem|null {
                if ($usage->usable instanceof RecipeVersion) {
                    return $usage->usable->recipe;
                }

                return $usage->usable instanceof Recipe
                    || $usage->usable instanceof Ingredient
                    || $usage->usable instanceof PackagingItem
                        ? $usage->usable
                        : null;
            })
            ->filter()
            ->unique(fn (Recipe|Ingredient|PackagingItem $target): string => $target::class.':'.$target->getKey());
        $recipeCount = $targets->filter(
            fn (Recipe|Ingredient|PackagingItem $target): bool => $target instanceof Recipe,
        )->count();
        $otherCount = $targets->count() - $recipeCount;

        return [
            'recipes' => $recipeCount,
            'other' => $otherCount,
            'total' => $recipeCount + $otherCount,
        ];
    }

    /**
     * @param  array{recipes:int, other:int, total:int}  $impact
     */
    private function deletionImpactLabel(array $impact): string
    {
        $recipes = trans_choice(
            'media_library.panel.delete_impact_recipes',
            $impact['recipes'],
            ['count' => $impact['recipes']],
        );
        $other = trans_choice(
            'media_library.panel.delete_impact_other',
            $impact['other'],
            ['count' => $impact['other']],
        );

        return match (true) {
            $impact['recipes'] > 0 && $impact['other'] > 0 => __(
                'media_library.panel.delete_impact_join',
                ['recipes' => $recipes, 'other' => $other],
            ),
            $impact['recipes'] > 0 => $recipes,
            $impact['other'] > 0 => $other,
            default => '',
        };
    }

    private function workspaceAsset(int $assetId, ?User $user): ?MediaAsset
    {
        $workspace = $user?->company();

        if (! $workspace instanceof Workspace) {
            return null;
        }

        return MediaAsset::query()
            ->where('workspace_id', $workspace->id)
            ->find($assetId);
    }

    private function mediaRole(User $user, Workspace $workspace): ?WorkspaceMemberRole
    {
        return $workspace->owner_user_id === $user->id
            ? WorkspaceMemberRole::Owner
            : WorkspaceMember::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('user_id', $user->id)
                ->first()
                ?->role;
    }

    private function workspaceLabel(int $labelId, ?User $user): ?MediaLabel
    {
        $workspace = $user?->company();

        return $workspace instanceof Workspace
            ? MediaLabel::query()
                ->where('workspace_id', $workspace->id)
                ->find($labelId)
            : null;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, int|string>
     */
    private function withoutId(array $ids, int $removedId): array
    {
        return collect($ids)
            ->reject(fn (int|string $id): bool => (int) $id === $removedId)
            ->values()
            ->all();
    }
}
