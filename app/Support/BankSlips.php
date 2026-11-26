<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Where bank slips (payment documents) live.
 *
 * New slips go to the PRIVATE disk only. Slips saved before this change sit on the PUBLIC disk, so reads and
 * deletes still look there as a fallback until `php artisan bank-slips:make-private --apply` has moved them.
 * The path stored in the database ("bank_slips/<random name>") is the same on both disks.
 */
final class BankSlips
{
    public const FOLDER = 'bank_slips';
    public const PRIVATE_DISK = 'private_slips';
    public const LEGACY_DISK = 'public';

    public static function store(UploadedFile $file): string
    {
        return (string) $file->store(self::FOLDER, self::PRIVATE_DISK);
    }

    /** The disk that holds this slip (private first, then the old public one), or null when the file is gone. */
    public static function diskHolding(string $path): ?Filesystem
    {
        foreach ([self::PRIVATE_DISK, self::LEGACY_DISK] as $name) {
            $disk = Storage::disk($name);
            if ($disk->exists($path)) {
                return $disk;
            }
        }

        return null;
    }

    public static function delete(string $path): void
    {
        Storage::disk(self::PRIVATE_DISK)->delete($path);
        Storage::disk(self::LEGACY_DISK)->delete($path);
    }
}
