<?php

namespace App\Enums;

enum IngredientTranslationOrigin: string
{
    case Legacy = 'legacy';
    case AiGenerated = 'ai_generated';
    case ReviewerEdited = 'reviewer_edited';
}
