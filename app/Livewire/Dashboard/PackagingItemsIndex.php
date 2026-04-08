<?php

namespace App\Livewire\Dashboard;

use App\Models\User;
use App\Models\UserPackagingItem;
use App\Services\CurrentAppUserResolver;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PackagingItemsIndex extends Component
{
    /**
     * @var array{name: string, unit_cost: string, notes: string}
     */
    public array $form = [
        'name' => '',
        'unit_cost' => '',
        'notes' => '',
    ];

    public ?string $saveMessage = null;

    #[Locked]
    public ?int $currentUserId = null;

    public function mount(CurrentAppUserResolver $resolver): void
    {
        $currentUser = $resolver->resolve();

        $this->currentUserId = $currentUser instanceof User ? $currentUser->id : null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'form.name' => ['required', 'string', 'max:255'],
            'form.unit_cost' => ['required', 'numeric', 'min:0'],
            'form.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function save(): void
    {
        if ($this->currentUserId === null) {
            return;
        }

        /** @var array{form: array{name: string, unit_cost: string|float|int, notes: string|null}} $validated */
        $validated = $this->validate();

        UserPackagingItem::query()->create([
            'user_id' => $this->currentUserId,
            'name' => trim($validated['form']['name']),
            'unit_cost' => (float) $validated['form']['unit_cost'],
            'currency' => 'EUR',
            'notes' => blank($validated['form']['notes']) ? null : trim((string) $validated['form']['notes']),
        ]);

        $this->form = [
            'name' => '',
            'unit_cost' => '',
            'notes' => '',
        ];

        $this->saveMessage = 'Packaging item saved.';
    }

    public function render(CurrentAppUserResolver $resolver): View
    {
        $currentUser = $resolver->resolve($this->currentUserId);
        $packagingItems = collect();

        if ($currentUser instanceof User) {
            $packagingItems = UserPackagingItem::query()
                ->where('user_id', $currentUser->id)
                ->orderBy('name')
                ->orderBy('id')
                ->get();
        }

        return view('livewire.dashboard.packaging-items-index', [
            'currentUser' => $currentUser,
            'packagingItems' => $packagingItems,
            'packagingItemCount' => $packagingItems->count(),
        ]);
    }
}
