<?php

namespace App\Enums;

/**
 * How a product currently stands towards buyers.
 *
 * This is derived from `products.is_active` and `products.published_at`, not
 * stored: those two columns are the truth, and this enum is the one place that
 * reads them the same way for the panel's badge, its filter and `Product`
 * itself. `Product::isPublished()` is the same question as
 * `publicationState() === Published`.
 */
enum ProductPublicationState: string
{
    /** Switched off entirely; never visible, whatever the publish date says. */
    case Inactive = 'inactive';

    /** Active but never published. */
    case Draft = 'draft';

    /** Active with a publish date still in the future. */
    case Scheduled = 'scheduled';

    /** Active and already published. */
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Inactive => 'Nonaktif',
            self::Draft => 'Draf',
            self::Scheduled => 'Terjadwal',
            self::Published => 'Terbit',
        };
    }

    /**
     * Whether buyers can see the product in this state.
     */
    public function isVisibleToBuyers(): bool
    {
        return $this === self::Published;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $state): array => ['value' => $state->value, 'label' => $state->label()],
            self::cases(),
        );
    }
}
