<?php

namespace App\Enums;

/**
 * Where on the storefront a banner is shown (P2-E8-T1).
 *
 * Only `Hero` is drawn today — the homepage slider. The others are named so
 * the form and the table already carry the choice when a page exists for them.
 */
enum BannerPosition: string
{
    case Hero = 'hero';
    case Sidebar = 'sidebar';
    case Middle = 'middle';
    case Footer = 'footer';

    public function label(): string
    {
        return match ($this) {
            self::Hero => 'Slider beranda',
            self::Sidebar => 'Sidebar',
            self::Middle => 'Tengah halaman',
            self::Footer => 'Footer',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $position): array => ['value' => $position->value, 'label' => $position->label()],
            self::cases(),
        );
    }
}
