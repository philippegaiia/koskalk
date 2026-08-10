<?php

namespace App\Support;

class InciName
{
    public static function normalize(?string $value): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/', ' ', trim((string) $value)));
    }
}
