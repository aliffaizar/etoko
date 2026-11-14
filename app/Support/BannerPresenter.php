<?php

namespace App\Support;

use App\Models\Banner;
use Illuminate\Support\Carbon;

/**
 * The shapes the banner screens and the homepage read (P2-E8-T1).
 */
final class BannerPresenter
{
    /**
     * What the homepage slider draws: the image, the words, and the link.
     *
     * @return array{id: int, title: string, subtitle: string|null, image_url: string|null, mobile_image_url: string|null, link_url: string|null}
     */
    public static function slide(Banner $banner): array
    {
        return [
            'id' => $banner->getKey(),
            'title' => $banner->title,
            'subtitle' => $banner->subtitle,
            'image_url' => $banner->imageUrl(),
            'mobile_image_url' => $banner->mobileImageUrl(),
            'link_url' => $banner->link_url,
        ];
    }

    /**
     * @return array{id: int, title: string, position_label: string, branch_label: string, period_label: string, state_label: string, state_tone: string, sort_order: int, is_active: bool, image_url: string|null}
     */
    public static function listRow(Banner $banner, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        [$stateLabel, $stateTone] = self::state($banner, $now);

        return [
            'id' => $banner->getKey(),
            'title' => $banner->title,
            'position_label' => $banner->position->label(),
            'branch_label' => $banner->branch->name ?? 'Semua cabang',
            'period_label' => self::period($banner),
            'state_label' => $stateLabel,
            'state_tone' => $stateTone,
            'sort_order' => $banner->sort_order,
            'is_active' => (bool) $banner->is_active,
            'image_url' => $banner->imageUrl(),
        ];
    }

    /**
     * @return array{id: int, title: string, subtitle: string|null, link_url: string|null, position: string, branch_id: int|null, sort_order: int, starts_at: string|null, ends_at: string|null, is_active: bool, image_url: string|null, mobile_image_url: string|null}
     */
    public static function detail(Banner $banner): array
    {
        return [
            'id' => $banner->getKey(),
            'title' => $banner->title,
            'subtitle' => $banner->subtitle,
            'link_url' => $banner->link_url,
            'position' => $banner->position->value,
            'branch_id' => $banner->branch_id,
            'sort_order' => $banner->sort_order,
            'starts_at' => $banner->starts_at?->toIso8601String(),
            'ends_at' => $banner->ends_at?->toIso8601String(),
            'is_active' => (bool) $banner->is_active,
            'image_url' => $banner->imageUrl(),
            'mobile_image_url' => $banner->mobileImageUrl(),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function state(Banner $banner, Carbon $now): array
    {
        return match (true) {
            ! $banner->is_active => ['Nonaktif', 'neutral'],
            $banner->starts_at !== null && $banner->starts_at->greaterThan($now) => ['Dijadwalkan', 'info'],
            $banner->ends_at !== null && $banner->ends_at->lessThan($now) => ['Berakhir', 'neutral'],
            default => ['Tampil', 'success'],
        };
    }

    private static function period(Banner $banner): string
    {
        if ($banner->starts_at === null && $banner->ends_at === null) {
            return 'Tanpa batas waktu';
        }

        $from = $banner->starts_at?->format('d M Y H:i') ?? 'sekarang';
        $until = $banner->ends_at?->format('d M Y H:i') ?? 'seterusnya';

        return "{$from} – {$until}";
    }
}
