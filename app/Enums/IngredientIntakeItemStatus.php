<?php

namespace App\Enums;

enum IngredientIntakeItemStatus: string
{
    case Draft = 'draft';
    case NeedsResolution = 'needs_resolution';
    case Queued = 'queued';
    case Researching = 'researching';
    case Ready = 'ready';
    case Failed = 'failed';
    case Approved = 'approved';
    case Promoted = 'promoted';
    case LinkedExisting = 'linked_existing';
    case Rejected = 'rejected';

    public function label(): string
    {
        return (string) __("ingredient_intake_admin.status.item.{$this->value}");
    }
}
