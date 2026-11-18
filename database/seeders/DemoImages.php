<?php

namespace Database\Seeders;

use Faker\Provider\Image;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Placeholder pictures for the demo seeders, drawn by Faker's image provider.
 *
 * Faker's `Image::image()` downloads a plain coloured picture with a caption from
 * the placeholder service Faker points at, so every product, category, banner
 * and review in the demo has something to show instead of an empty frame.
 *
 * Three cases where no picture is produced, and the caller carries on without
 * one — a demo with blank frames is a degradation, a seeder that dies over a
 * picture is a failure:
 *
 * - **under the test suite**, so tests never reach the network;
 * - **when the service cannot be reached**, checked once per run with a short
 *   timeout, because Faker's own download has none and would hang the seeder
 *   on a dead connection;
 * - **when one download fails**, which Faker reports as `false`.
 */
final class DemoImages
{
    /** Remembered for the run, so an unreachable service is asked once. */
    private static ?bool $reachable = null;

    /**
     * A downloaded picture wrapped as an upload, ready for the same Actions
     * the panel's forms use. Remove it with `discard()` once it is stored.
     */
    public static function upload(string $caption, int $width = 800, int $height = 800): ?UploadedFile
    {
        if (! self::available()) {
            return null;
        }

        try {
            $path = Image::image(null, $width, $height, $caption, true, false);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($path) || ! is_file($path)) {
            return null;
        }

        return new UploadedFile($path, Str::slug($caption).'.png', 'image/png', null, true);
    }

    /**
     * A downloaded picture written straight onto the public disk, for the
     * places that only keep a path (review photos, return photos, category
     * pictures). Returns the stored path, or null when there is no picture.
     */
    public static function store(string $directory, string $caption, int $width = 800, int $height = 800): ?string
    {
        $file = self::upload($caption, $width, $height);

        if (! $file instanceof UploadedFile) {
            return null;
        }

        $path = Storage::disk('public')->putFile($directory, $file);

        self::discard($file);

        return is_string($path) ? $path : null;
    }

    /**
     * Remove the temporary download behind an upload.
     */
    public static function discard(?UploadedFile $file): void
    {
        if ($file instanceof UploadedFile && is_file($file->getPathname())) {
            @unlink($file->getPathname());
        }
    }

    private static function available(): bool
    {
        if (app()->runningUnitTests()) {
            return false;
        }

        if (self::$reachable === null) {
            try {
                self::$reachable = Http::timeout(5)->get(Image::BASE_URL.'/10x10.png')->successful();
            } catch (Throwable) {
                self::$reachable = false;
            }
        }

        return self::$reachable;
    }
}
