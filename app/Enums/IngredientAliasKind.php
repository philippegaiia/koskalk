<?php

namespace App\Enums;

enum IngredientAliasKind: string
{
    case Common = 'common';
    case Botanical = 'botanical';
    case Spelling = 'spelling';
    case Former = 'former';

    public function label(): string
    {
        return match ($this) {
            self::Common => __('ingredients.editor.identity.alias_kinds.common'),
            self::Botanical => __('ingredients.editor.identity.alias_kinds.botanical'),
            self::Spelling => __('ingredients.editor.identity.alias_kinds.spelling'),
            self::Former => __('ingredients.editor.identity.alias_kinds.former'),
        };
    }
}
