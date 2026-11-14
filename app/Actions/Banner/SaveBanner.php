<?php

namespace App\Actions\Banner;

use App\Models\Banner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Creates or updates a banner and its images (P2-E8-T1).
 *
 * Images go to the `public` disk under `banners/`, and the row stores only the
 * path. When an image is replaced the old file is removed after the row points
 * at the new one, so a failed save never leaves the banner without a picture.
 */
final class SaveBanner
{
    private const DISK = 'public';

    private const DIRECTORY = 'banners';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        array $attributes,
        ?Banner $banner = null,
        ?UploadedFile $image = null,
        ?UploadedFile $mobileImage = null,
    ): Banner {
        $stored = [];

        if ($image instanceof UploadedFile) {
            $stored['image_path'] = $image->store(self::DIRECTORY, self::DISK);
        }

        if ($mobileImage instanceof UploadedFile) {
            $stored['mobile_image_path'] = $mobileImage->store(self::DIRECTORY, self::DISK);
        }

        $replaced = [];

        if ($banner !== null) {
            if (isset($stored['image_path'])) {
                $replaced[] = $banner->image_path;
            }

            if (isset($stored['mobile_image_path'])) {
                $replaced[] = $banner->mobile_image_path;
            }
        }

        try {
            $saved = DB::transaction(function () use ($attributes, $banner, $stored): Banner {
                $row = $banner ?? new Banner;
                $row->fill([...$attributes, ...$stored])->save();

                return $row;
            });
        } catch (Throwable $failed) {
            // The row was not written, so the pictures just stored belong to
            // nothing; the ones they were to replace are still in use.
            foreach (array_filter($stored) as $path) {
                Storage::disk(self::DISK)->delete($path);
            }

            throw $failed;
        }

        foreach (array_filter($replaced) as $path) {
            Storage::disk(self::DISK)->delete($path);
        }

        return $saved;
    }
}
