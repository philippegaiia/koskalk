<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use App\Models\UserEntitlement;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rules\Password;
use Laravel\Paddle\Subscription;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('User')
                    ->description('Manage the Laravel account identity and admin access flag.')
                    ->icon(Heroicon::OutlinedUsers)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        DateTimePicker::make('email_verified_at')
                            ->label('Email verified at'),
                        Toggle::make('is_admin')
                            ->label('Admin access')
                            ->required(),
                        TextInput::make('password')
                            ->label(fn (string $operation): string => $operation === 'create' ? 'Password' : 'New password')
                            ->password()
                            ->revealable()
                            ->saved(fn (?string $state): bool => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->maxLength(255)
                            ->rule(Password::defaults())
                            ->helperText('Required for new users. Fill on edit only when you want to reset the password.')
                            ->columnSpanFull(),
                        TextInput::make('password_confirmation')
                            ->label('Confirm password')
                            ->password()
                            ->revealable()
                            ->dehydrated(false)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->requiredWith('password')
                            ->same('password')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Current access')
                    ->description('Read-only plan, Paddle subscription, and usage context for support.')
                    ->icon(Heroicon::CreditCard)
                    ->visible(fn (?User $record): bool => $record instanceof User)
                    ->schema([
                        TextEntry::make('current_plan')
                            ->label('Plan')
                            ->state(fn (?User $record): string => $record instanceof User ? self::currentPlanName($record) : '-'),
                        TextEntry::make('entitlement_source')
                            ->label('Entitlement source')
                            ->state(fn (?User $record): string => $record instanceof User ? self::currentEntitlementSource($record) : '-'),
                        TextEntry::make('subscription_status')
                            ->label('Subscription')
                            ->state(fn (?User $record): string => $record instanceof User ? self::subscriptionStatus($record) : '-'),
                        TextEntry::make('paddle_subscription_id')
                            ->label('Paddle subscription ID')
                            ->state(fn (?User $record): string => $record instanceof User ? self::paddleSubscriptionId($record) : '-'),
                        TextEntry::make('paddle_price_ids')
                            ->label('Paddle price IDs')
                            ->state(fn (?User $record): string => $record instanceof User ? self::paddlePriceIds($record) : '-')
                            ->columnSpanFull(),
                        TextEntry::make('usage_summary')
                            ->label('Usage')
                            ->state(fn (?User $record): string => $record instanceof User ? self::usageSummary($record) : '-')
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
            ]);
    }

    private static function currentPlanName(User $user): string
    {
        return self::currentEntitlement($user)?->plan?->name ?? 'No plan';
    }

    private static function currentEntitlementSource(User $user): string
    {
        $entitlement = self::currentEntitlement($user);

        if (! $entitlement instanceof UserEntitlement) {
            return 'None';
        }

        return str($entitlement->source ?? 'manual')->replace('_', ' ')->headline()->toString();
    }

    private static function subscriptionStatus(User $user): string
    {
        $subscription = self::latestSubscription($user);

        if (! $subscription instanceof Subscription) {
            return 'None';
        }

        return str((string) $subscription->status)->replace('_', ' ')->headline()->toString();
    }

    private static function paddleSubscriptionId(User $user): string
    {
        return self::latestSubscription($user)?->paddle_id ?? 'None';
    }

    private static function paddlePriceIds(User $user): string
    {
        $subscription = self::latestSubscription($user);

        if (! $subscription instanceof Subscription) {
            return 'None';
        }

        $subscription->loadMissing('items');

        $priceIds = $subscription->items
            ->pluck('price_id')
            ->filter()
            ->implode(', ');

        return $priceIds === '' ? 'None' : $priceIds;
    }

    private static function usageSummary(User $user): string
    {
        return sprintf(
            'Saved recipes: %d · Private ingredients: %d · Production batches: %d',
            $user->recipes()->count(),
            $user->privateIngredients()->count(),
            $user->productionBatches()->count(),
        );
    }

    private static function currentEntitlement(User $user): ?UserEntitlement
    {
        return $user->entitlements()
            ->active()
            ->with('plan')
            ->latest('starts_at')
            ->latest('id')
            ->first();
    }

    private static function latestSubscription(User $user): ?Subscription
    {
        return $user->subscriptions()
            ->with('items')
            ->latest('created_at')
            ->latest('id')
            ->first();
    }
}
