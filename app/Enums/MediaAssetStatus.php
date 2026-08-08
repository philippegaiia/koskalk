<?php

namespace App\Enums;

enum MediaAssetStatus: string
{
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
