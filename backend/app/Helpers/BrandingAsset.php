<?php

namespace App\Helpers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BrandingAsset
{
    private const DISK = 'public';

    private const SHIPPED_LOGOS = [
        'launchr_black.svg',
        'launchr_logo.svg',
        'launchr_white.svg',
        'synctalents.png',
        'sblogo.svg',
        'stlogo.svg',
        'springboard-logo.svg',
    ];

    private const PROTECTED = [
        'launchr_black.svg',
        'launchr_logo.svg',
    ];

    public static function resolve(?string $filename): ?string
    {
        $filename = self::safeName($filename);
        if ($filename === '') {
            return null;
        }

        $diskPath = Storage::disk(self::DISK)->path($filename);
        // Disk first so container writes win; public_path keeps pre-Docker uploads on standalone servers.
        if (is_file($diskPath)) {
            return $diskPath;
        }

        $legacy = public_path($filename);

        return is_file($legacy) ? $legacy : null;
    }

    public static function storeUploaded(UploadedFile $file, string $name): string
    {
        $name = self::safeName($name);
        $stored = Storage::disk(self::DISK)->putFileAs('', $file, $name);
        if ($stored === false) {
            throw new RuntimeException('Failed to store branding asset '.$name);
        }

        return $name;
    }

    public static function delete(string $filename): bool
    {
        $filename = self::safeName($filename);
        if ($filename === '' || in_array($filename, self::PROTECTED, true)) {
            return false;
        }

        $deleted = false;
        $disk = Storage::disk(self::DISK);
        if ($disk->exists($filename)) {
            $disk->delete($filename);
            $deleted = true;
        }

        $legacy = public_path($filename);
        if (is_file($legacy)) {
            unlink($legacy);
            $deleted = true;
        }

        return $deleted;
    }

    public static function listLogos(): array
    {
        $found = [];

        foreach (Storage::disk(self::DISK)->files() as $path) {
            $name = basename($path);
            if (self::isListableLogo($name)) {
                $found[$name] = $name;
            }
        }

        foreach (scandir(public_path('/')) ?: [] as $file) {
            $name = basename((string) $file);
            if (self::isListableLogo($name)) {
                $found[$name] = $name;
            }
        }

        return array_values($found);
    }

    public static function isProtected(string $filename): bool
    {
        return in_array(self::safeName($filename), self::PROTECTED, true);
    }

    private static function safeName(?string $filename): string
    {
        return $filename === null ? '' : basename($filename);
    }

    private static function isListableLogo(string $name): bool
    {
        return str_starts_with($name, 'system_logo_')
            || in_array($name, self::SHIPPED_LOGOS, true);
    }
}
