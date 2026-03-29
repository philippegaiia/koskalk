<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class MediaStorage
{
    public static function publicDisk(): string
    {
        $disk = (string) config('filesystems.default', 'public');

        if ($disk === 'local') {
            return 'public';
        }

        return $disk;
    }

    public static function publicUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return Storage::disk(static::publicDisk())->url($path);
    }
}
