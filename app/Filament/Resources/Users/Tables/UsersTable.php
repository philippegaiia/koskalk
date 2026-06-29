<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use App\Models\UserEntitlement;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Paddle\Subscription;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['entitlements.plan', 'subscriptions.items'])
                ->withCount(['recipes', 'privateIngredients', 'productionBatches']))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (User $record): string => $record->email),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('current_plan')
                    ->label('Plan')
                    ->state(fn (User $record): string => self::currentPlanName($record))
                    ->badge(),
                TextColumn::make('subscription_status')
                    ->label('Subscription')
                    ->state(fn (User $record): string => self::subscriptionStatus($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success',
                        'Canceled', 'Paused' => 'warning',
                        'None' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('recipes_count')
                    ->label('Recipes')
                    ->sortable(),
                TextColumn::make('private_ingredients_count')
                    ->label('Ingredients')
                    ->sortable(),
                TextColumn::make('production_batches_count')
                    ->label('Batches')
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('is_admin')
                    ->label('Admin')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function currentPlanName(User $user): string
    {
        return self::currentEntitlement($user)?->plan?->name ?? 'No plan';
    }

    private static function subscriptionStatus(User $user): string
    {
        $subscription = self::latestSubscription($user);

        if (! $subscription instanceof Subscription) {
            return 'None';
        }

        return str((string) $subscription->status)->replace('_', ' ')->headline()->toString();
    }

    private static function currentEntitlement(User $user): ?UserEntitlement
    {
        $entitlements = $user->relationLoaded('entitlements')
            ? $user->entitlements
            : $user->entitlements()->with('plan')->get();

        return $entitlements
            ->filter(fn (UserEntitlement $entitlement): bool => $entitlement->status === 'active'
                && ($entitlement->starts_at === null || $entitlement->starts_at->lte(now()))
                && ($entitlement->ends_at === null || $entitlement->ends_at->gt(now())))
            ->sortByDesc(fn (UserEntitlement $entitlement): int => $entitlement->starts_at?->getTimestamp() ?? 0)
            ->sortByDesc('id')
            ->first();
    }

    private static function latestSubscription(User $user): ?Subscription
    {
        $subscriptions = $user->relationLoaded('subscriptions')
            ? $user->subscriptions
            : $user->subscriptions()->with('items')->get();

        return $subscriptions
            ->sortByDesc(fn (Subscription $subscription): int => $subscription->created_at?->getTimestamp() ?? 0)
            ->sortByDesc('id')
            ->first();
    }
}
