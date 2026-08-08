<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PackagingCategory: string implements HasLabel
{
    case Box = 'box';
    case Jar = 'jar';
    case Bottle = 'bottle';
    case Lid = 'lid';
    case Cap = 'cap';
    case Label = 'label';
    case Tube = 'tube';
    case Pump = 'pump';
    case Shipping = 'shipping';
    case Other = 'other';

    public function getLabel(): string
    {
        return __("packaging.categories.{$this->value}");
    }
}
