<?php

use App\Actions\Purchasing\SaveSupplier;
use App\Actions\Purchasing\SaveSupplierListing;
use App\ListingPriceBasis;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\UserIngredientPrice;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\OwnerType;
use App\Services\ProductionBenchAccess;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('creates and updates a workspace supplier with normalized optional fields', function (): void {
    [$owner, $workspace] = activePurchasingWorkspace();
    $action = app(SaveSupplier::class);

    $supplier = $action->handle($owner, $workspace, [
        'code' => 'NORTHERN_01',
        'name' => '  Northern Oils  ',
        'address_line_1' => '  12 Market Street ',
        'address_line_2' => ' ',
        'city' => ' Leeds ',
        'region' => ' Yorkshire ',
        'postal_code' => ' LS1 1AA ',
        'country_code' => 'gb',
        'website' => 'https://northern-oils.example',
        'contact_name' => ' Sam Buyer ',
        'email' => 'sam@northern-oils.example',
        'phone' => ' ',
        'default_currency' => 'gbp',
        'notes' => ' ',
        'is_active' => true,
    ]);

    expect($supplier->name)->toBe('Northern Oils')
        ->and($supplier->address_line_2)->toBeNull()
        ->and($supplier->phone)->toBeNull()
        ->and($supplier->country_code)->toBe('GB')
        ->and($supplier->default_currency)->toBe('GBP');

    $updated = $action->handle($owner, $workspace, [
        'name' => 'Northern Oils Europe',
        'default_currency' => 'eur',
        'is_active' => false,
    ], $supplier);

    expect($updated->is($supplier))->toBeTrue()
        ->and($updated->name)->toBe('Northern Oils Europe')
        ->and($updated->default_currency)->toBe('EUR')
        ->and($updated->is_active)->toBeFalse();
});

it('prevents updating a supplier from another workspace', function (): void {
    [$owner, $workspace] = activePurchasingWorkspace();
    $otherWorkspace = Workspace::factory()->create();
    $foreignSupplier = Supplier::factory()->for($otherWorkspace)->create([
        'name' => 'Foreign Supplier',
    ]);

    $exception = captureValidationException(
        fn (): Supplier => app(SaveSupplier::class)->handle($owner, $workspace, [
            'name' => 'Attempted update',
            'default_currency' => 'EUR',
            'is_active' => true,
        ], $foreignSupplier),
    );

    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception?->errors())->toBe([
            'supplier' => ['The supplier does not belong to this workspace.'],
        ])
        ->and(Supplier::query()->count())->toBe(1)
        ->and($foreignSupplier->fresh()?->name)->toBe('Foreign Supplier');
});

it('creates a supplier without an optional country code', function (): void {
    [$owner, $workspace] = activePurchasingWorkspace();

    $supplier = app(SaveSupplier::class)->handle($owner, $workspace, [
        'code' => 'COUNTRY_FREE',
        'name' => 'Country-free supplier',
        'default_currency' => 'EUR',
        'is_active' => true,
    ]);

    expect($supplier->country_code)->toBeNull();
});

it('rejects unsupported supplier currencies and non-http websites at the action boundary', function (): void {
    [$owner, $workspace] = activePurchasingWorkspace();

    foreach ([
        ['default_currency' => 'ZZZ', 'website' => 'https://supplier.example'],
        ['default_currency' => 'EUR', 'website' => 'data:text/html,unsafe'],
        ['default_currency' => 'EUR', 'website' => 'smb://files.example/supplier'],
    ] as $attributes) {
        $exception = captureValidationException(
            fn (): Supplier => app(SaveSupplier::class)->handle($owner, $workspace, [
                'code' => 'BOUNDARY',
                'name' => 'Boundary supplier',
                'is_active' => true,
                ...$attributes,
            ]),
        );

        expect($exception)->toBeInstanceOf(ValidationException::class);
    }

    expect(Supplier::query()->count())->toBe(0);
});

it('preserves only the unchanged historical currency exception when updating a supplier', function (): void {
    [$owner, $workspace] = activePurchasingWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create([
        'default_currency' => 'HRK',
        'city' => 'Zagreb',
    ]);
    $action = app(SaveSupplier::class);

    $updated = $action->handle($owner, $workspace, ['city' => 'Split'], $supplier);

    expect($updated->default_currency)->toBe('HRK')
        ->and($updated->city)->toBe('Split');

    $replacementException = captureValidationException(
        fn (): Supplier => $action->handle($owner, $workspace, [
            'default_currency' => 'ZWL',
        ], $supplier),
    );
    $newHistoricalException = captureValidationException(
        fn (): Supplier => $action->handle($owner, $workspace, [
            'code' => 'HISTORICAL',
            'name' => 'Historical currency supplier',
            'default_currency' => 'HRK',
            'is_active' => true,
        ]),
    );
    $invalidPersistedSupplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'ZZZ']);
    $invalidPersistedException = captureValidationException(
        fn (): Supplier => $action->handle($owner, $workspace, ['city' => 'Paris'], $invalidPersistedSupplier),
    );

    expect($replacementException)->toBeInstanceOf(ValidationException::class)
        ->and($newHistoricalException)->toBeInstanceOf(ValidationException::class)
        ->and($invalidPersistedException)->toBeInstanceOf(ValidationException::class)
        ->and($supplier->fresh()?->default_currency)->toBe('HRK');
});

it('rejects unsupported listing currencies at the action boundary', function (): void {
    [$owner, $workspace] = activePurchasingWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();

    $exception = captureValidationException(
        fn (): SupplierListing => app(SaveSupplierListing::class)->handle(
            $owner,
            $workspace,
            $supplier,
            $ingredient,
            listingAttributes(['currency' => 'ZZZ']),
        ),
    );

    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception?->errors())->toHaveKey('currency')
        ->and(SupplierListing::query()->count())->toBe(0);
});

it('preserves only the unchanged historical currency exception when updating a listing', function (): void {
    [$owner, $workspace] = activePurchasingWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'EUR']);
    $ingredient = Ingredient::factory()->create();
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for($ingredient)
        ->create(['currency' => 'HRK']);
    $action = app(SaveSupplierListing::class);

    $updated = $action->handle(
        $owner,
        $workspace,
        $supplier,
        $ingredient,
        listingAttributes(['notes' => 'Updated note']),
        $listing,
    );

    expect($updated->currency)->toBe('HRK')
        ->and($updated->notes)->toBe('Updated note');

    $replacementException = captureValidationException(
        fn (): SupplierListing => $action->handle(
            $owner,
            $workspace,
            $supplier,
            $ingredient,
            listingAttributes(['currency' => 'ZWL']),
            $listing,
        ),
    );
    $invalidPersistedListing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for($ingredient)
        ->create(['currency' => 'ZZZ']);
    $invalidPersistedException = captureValidationException(
        fn (): SupplierListing => $action->handle(
            $owner,
            $workspace,
            $supplier,
            $ingredient,
            listingAttributes(['notes' => 'Must not save']),
            $invalidPersistedListing,
        ),
    );

    expect($replacementException)->toBeInstanceOf(ValidationException::class)
        ->and($invalidPersistedException)->toBeInstanceOf(ValidationException::class)
        ->and($listing->fresh()?->currency)->toBe('HRK');
});

it('saves a per-unit mass listing without updating user ingredient price memory', function (): void {
    [$owner, $workspace] = activePurchasingWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'EUR']);
    $ingredient = Ingredient::factory()->create();
    $rememberedPrice = UserIngredientPrice::query()->create([
        'user_id' => $owner->id,
        'ingredient_id' => $ingredient->id,
        'price_per_kg' => '8.7500',
        'currency' => 'GBP',
        'last_used_at' => '2026-07-20 10:30:00',
    ]);

    $listing = app(SaveSupplierListing::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        subject: $ingredient,
        attributes: listingAttributes([
            'purchase_format' => '200 kg drum',
            'net_quantity' => '200',
            'net_unit' => 'kg',
            'price_basis' => ListingPriceBasis::PerUnit,
            'price_amount' => '4.20',
            'price_unit' => 'kg',
        ]),
    );

    expect($listing->ingredient_id)->toBe($ingredient->id)
        ->and($listing->user_packaging_item_id)->toBeNull()
        ->and($listing->canonical_quantity_per_purchase_format)->toBe('200000.000000000')
        ->and($listing->price_basis)->toBe(ListingPriceBasis::PerUnit)
        ->and($listing->price_amount)->toBe('4.200000000')
        ->and($listing->price_unit)->toBe('kg')
        ->and($listing->total_price)->toBe('840.000000000')
        ->and($rememberedPrice->fresh()?->price_per_kg)->toBe('8.7500')
        ->and($rememberedPrice->fresh()?->currency)->toBe('GBP')
        ->and($rememberedPrice->fresh()?->last_used_at?->toDateTimeString())->toBe('2026-07-20 10:30:00')
        ->and(UserIngredientPrice::query()->count())->toBe(1);

    app(SaveSupplierListing::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        subject: $ingredient,
        attributes: listingAttributes([
            'purchase_format' => '200 kg drum',
            'net_quantity' => '200',
            'net_unit' => 'kg',
            'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'price_amount' => '900',
        ]),
        listing: $listing,
    );

    expect($rememberedPrice->fresh()?->price_per_kg)->toBe('8.7500')
        ->and($rememberedPrice->fresh()?->currency)->toBe('GBP')
        ->and($rememberedPrice->fresh()?->last_used_at?->toDateTimeString())->toBe('2026-07-20 10:30:00')
        ->and(UserIngredientPrice::query()->count())->toBe(1);
});

it('persists the same normalized mass quantity used to calculate listing prices', function (): void {
    [$owner, $workspace] = activePurchasingWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();

    $listing = app(SaveSupplierListing::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        subject: $ingredient,
        attributes: listingAttributes([
            'net_quantity' => '1.0000000006',
            'net_unit' => 'lb',
            'price_basis' => ListingPriceBasis::PerUnit,
            'price_amount' => '2',
            'price_unit' => 'lb',
        ]),
    );

    expect($listing->net_quantity)->toBe('1.000000001')
        ->and($listing->canonical_quantity_per_purchase_format)->toBe('453.592370454')
        ->and($listing->total_price)->toBe('2.000000002');
});

it('allows listing price units to be omitted when a convention supplies them', function (): void {
    [$owner, $workspace] = activePurchasingWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $packaging = UserPackagingItem::factory()->for($owner)->create();
    $action = app(SaveSupplierListing::class);
    $totalMassAttributes = listingAttributes([
        'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
        'price_amount' => '100',
    ]);
    $countPerUnitAttributes = listingAttributes([
        'net_quantity' => '500',
        'net_unit' => 'count',
        'price_basis' => ListingPriceBasis::PerUnit,
        'price_amount' => '0.18',
    ]);
    unset($totalMassAttributes['price_unit'], $countPerUnitAttributes['price_unit']);

    $totalMass = $action->handle($owner, $workspace, $supplier, $ingredient, $totalMassAttributes);
    $countPerUnit = $action->handle($owner, $workspace, $supplier, $packaging, $countPerUnitAttributes);

    expect($totalMass->price_unit)->toBeNull()
        ->and($countPerUnit->price_unit)->toBe('count')
        ->and($countPerUnit->total_price)->toBe('90.000000000');
});

it('updates a count listing using total purchase-format pricing', function (): void {
    [$owner, $workspace] = activePurchasingWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $packaging = UserPackagingItem::factory()->for($owner)->create();

    $listing = app(SaveSupplierListing::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        subject: $packaging,
        attributes: listingAttributes([
            'purchase_format' => 'Carton of 500 bottles',
            'net_quantity' => '500',
            'net_unit' => 'count',
            'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'price_amount' => '100',
            'price_unit' => null,
        ]),
    );

    $updated = app(SaveSupplierListing::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        subject: $packaging,
        attributes: listingAttributes([
            'purchase_format' => 'Carton of 500 bottles',
            'net_quantity' => '500',
            'net_unit' => 'count',
            'price_basis' => ListingPriceBasis::PerUnit,
            'price_amount' => '0.18',
            'price_unit' => 'count',
        ]),
        listing: $listing,
    );

    expect($updated->is($listing))->toBeTrue()
        ->and($updated->canonical_quantity_per_purchase_format)->toBe('500.000000000')
        ->and($updated->price_basis)->toBe(ListingPriceBasis::PerUnit)
        ->and($updated->price_unit)->toBe('count')
        ->and($updated->total_price)->toBe('90.000000000');
});

it('accepts public and workspace-shared ingredients but rejects inaccessible private ingredients', function (): void {
    [$owner, $workspace] = activePurchasingWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $public = Ingredient::factory()->create();
    $shared = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
    ]);
    $private = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => User::factory()->create()->id,
        'visibility' => Visibility::Private,
    ]);

    $action = app(SaveSupplierListing::class);
    $attributes = listingAttributes();

    expect($action->handle($owner, $workspace, $supplier, $public, $attributes))->toBeInstanceOf(SupplierListing::class)
        ->and($action->handle($owner, $workspace, $supplier, $shared, $attributes))->toBeInstanceOf(SupplierListing::class);

    $exception = captureValidationException(
        fn (): SupplierListing => $action->handle($owner, $workspace, $supplier, $private, $attributes),
    );

    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception?->errors())->toHaveKey('subject')
        ->and(SupplierListing::query()->count())->toBe(2);
});

it('rejects a private ingredient owned by another accessible workspace', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $otherWorkspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $foreignPrivateIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $otherWorkspace->id,
        'workspace_id' => $otherWorkspace->id,
        'visibility' => Visibility::Private,
    ]);

    $exception = captureValidationException(
        fn (): SupplierListing => app(SaveSupplierListing::class)->handle(
            $owner,
            $workspace,
            $supplier,
            $foreignPrivateIngredient,
            listingAttributes(),
        ),
    );

    expect($foreignPrivateIngredient->isAccessibleBy($owner))->toBeTrue()
        ->and($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception?->errors())->toHaveKey('subject')
        ->and(SupplierListing::query()->count())->toBe(0);
});

it('rejects packaging owned by anyone other than the workspace owner', function (): void {
    [$owner, $workspace] = activePurchasingWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $foreignPackaging = UserPackagingItem::factory()->create();

    $exception = captureValidationException(
        fn (): SupplierListing => app(SaveSupplierListing::class)->handle(
            $owner,
            $workspace,
            $supplier,
            $foreignPackaging,
            listingAttributes([
                'net_quantity' => '500',
                'net_unit' => 'count',
                'price_amount' => '100',
                'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
                'price_unit' => null,
            ]),
        ),
    );

    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception?->errors())->toHaveKey('packaging_item')
        ->and(SupplierListing::query()->count())->toBe(0);
});

it('rejects a listing supplier from another workspace', function (): void {
    [$owner, $workspace] = activePurchasingWorkspace();
    $foreignSupplier = Supplier::factory()->create([
        'name' => 'Foreign Supplier',
    ]);
    $ingredient = Ingredient::factory()->create();

    $exception = captureValidationException(
        fn (): SupplierListing => app(SaveSupplierListing::class)->handle(
            $owner,
            $workspace,
            $foreignSupplier,
            $ingredient,
            listingAttributes(),
        ),
    );

    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception?->errors())->toBe([
            'supplier' => ['The supplier does not belong to this workspace.'],
        ])
        ->and(SupplierListing::query()->count())->toBe(0)
        ->and($foreignSupplier->fresh()?->name)->toBe('Foreign Supplier');
});

it('requires the listing subject to exist', function (): void {
    [$owner, $workspace] = activePurchasingWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $missingIngredient = Ingredient::factory()->make();

    $exception = captureValidationException(
        fn (): SupplierListing => app(SaveSupplierListing::class)->handle(
            $owner,
            $workspace,
            $supplier,
            $missingIngredient,
            listingAttributes(),
        ),
    );

    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception?->errors())->toHaveKey('subject')
        ->and(SupplierListing::query()->count())->toBe(0);
});

it('blocks supplier and listing changes when production bench is read-only', function (): void {
    [$owner, $workspace] = activePurchasingWorkspace();
    app(ProductionBenchAccess::class)->cancel($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();

    $supplierException = captureValidationException(
        fn (): Supplier => app(SaveSupplier::class)->handle($owner, $workspace, [
            'name' => 'Blocked',
            'default_currency' => 'EUR',
            'is_active' => true,
        ]),
    );
    $listingException = captureValidationException(
        fn (): SupplierListing => app(SaveSupplierListing::class)->handle(
            $owner,
            $workspace,
            $supplier,
            $ingredient,
            listingAttributes(),
        ),
    );

    expect($supplierException)->toBeInstanceOf(ValidationException::class)
        ->and($supplierException?->errors())->toBe([
            'production_bench' => ['Production Bench is read-only while the add-on is cancelled.'],
        ])
        ->and($listingException)->toBeInstanceOf(ValidationException::class)
        ->and($listingException?->errors())->toBe([
            'production_bench' => ['Production Bench is read-only while the add-on is cancelled.'],
        ])
        ->and(Supplier::query()->count())->toBe(1)
        ->and(SupplierListing::query()->count())->toBe(0)
        ->and($supplier->fresh()?->name)->toBe($supplier->name);
});

/** @return array{0: User, 1: Workspace} */
function activePurchasingWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);

    return [$owner, $workspace];
}

/** @param array<string, mixed> $overrides */
function listingAttributes(array $overrides = []): array
{
    return [
        'purchase_format' => '5 kg pail',
        'net_quantity' => '5',
        'net_unit' => 'kg',
        'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
        'price_amount' => '50',
        'price_unit' => null,
        'supplier_sku' => 'SKU-5',
        'supplier_name' => null,
        'container' => 'pail',
        'minimum_packs' => 1,
        'notes' => null,
        'is_active' => true,
        ...$overrides,
    ];
}

function captureValidationException(Closure $operation): ?ValidationException
{
    try {
        $operation();
    } catch (ValidationException $exception) {
        return $exception;
    }

    return null;
}
