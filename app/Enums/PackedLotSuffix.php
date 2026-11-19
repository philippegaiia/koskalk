<?php

namespace App\Enums;

/**
 * How a packed lot code continues the batch number: a running letter, the
 * size's declared content, or its SKU.
 */
enum PackedLotSuffix: string
{
    case Letter = 'letter';
    case Content = 'content';
    case Sku = 'sku';
}
