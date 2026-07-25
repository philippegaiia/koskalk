<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\MediaAssetStatus;
use App\Models\Ingredient;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\Recipe;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\CurrentAppUserResolver;
use App\Services\EntitlementService;
use App\Services\MediaAssetLibraryService;
use App\Services\MediaAssetUploadService;
use App\WorkspaceMemberRole;
use Illuminate\Contracts\View\View;
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

    public function uploadAsset(
        CurrentAppUserResolver $resolver,
        MediaAssetUploadService $uploads,
    ): void {
        $user = $resolver->resolve();
        $workspace = $user?->company();

        abort_unless($user instanceof User && $workspace instanceof Workspace, 403);

        $this->validate([
            'upload' => ['required', 'file', 'max:10240'],
        ]);

        $asset = $uploads->start($user, $workspace, $this->upload);

        $this->reset('upload');
        $this->showAppNotification(
            __('media_library.messages.upload_processing', ['name' => $asset->original_filename]),
        );
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

        abort_unless($user instanceof User && $asset instanceof MediaAsset, 404);

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

        return view('livewire.dashboard.media-library-index', [
            'assets' => $assets,
            'usage' => $usage,
            'user' => $user,
            'canUpdateMedia' => $canUpdateMedia,
            'canDeleteMedia' => $canDeleteMedia,
            'hasProcessingAssets' => $hasProcessingAssets,
            'selectedAsset' => $selectedAsset,
            'selectedUsageGroups' => $this->selectedUsageGroups($selectedAsset),
        ]);
    }

    private function assets(Workspace $workspace): LengthAwarePaginator
    {
        return MediaAsset::query()
            ->where('workspace_id', $workspace->id)
            ->withCount('usages')
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
            ->latest()
            ->paginate(24);
    }

    private function selectedAsset(Workspace $workspace): ?MediaAsset
    {
        if ($this->selectedAssetId === null) {
            return null;
        }

        return MediaAsset::query()
            ->where('workspace_id', $workspace->id)
            ->with([
                'usages' => fn ($query) => $query->latest(),
                'usages.usable',
            ])
            ->withCount('usages')
            ->find($this->selectedAssetId);
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

        return $asset->usages
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
                $usage->usable instanceof UserPackagingItem => 'packaging',
                default => 'other',
            });
    }

    private function usageTargetName(MediaAssetUsage $usage): string
    {
        return match (true) {
            $usage->usable instanceof Recipe => $usage->usable->name,
            $usage->usable instanceof Ingredient => $usage->usable->display_name,
            $usage->usable instanceof UserPackagingItem => $usage->usable->name,
            default => __('media_library.missing_target'),
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
}
