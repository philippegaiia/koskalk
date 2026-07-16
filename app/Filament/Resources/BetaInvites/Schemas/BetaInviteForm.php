<?php

namespace App\Filament\Resources\BetaInvites\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class BetaInviteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Invite a Free beta workspace owner')
                    ->description('The recipient receives a single-use email link to create their verified Soapkraft workspace.')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->schema([
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        TextInput::make('workspace_name')
                            ->label('Company / workspace name')
                            ->required()
                            ->maxLength(255),
                    ]),
            ]);
    }
}
