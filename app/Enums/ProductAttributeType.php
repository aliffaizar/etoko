<?php

namespace App\Enums;

/**
 * How a product attribute is presented when a buyer picks a variant.
 */
enum ProductAttributeType: string
{
    case Select = 'select';
    case Color = 'color';

    public function label(): string
    {
        return match ($this) {
            self::Select => 'Pilihan',
            self::Color => 'Warna',
        };
    }

    /**
     * Whether values of this attribute carry a colour swatch (`color_hex`).
     */
    public function usesColorSwatch(): bool
    {
        return $this === self::Color;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
