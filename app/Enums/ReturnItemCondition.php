<?php

namespace App\Enums;

/**
 * What state a returned line came back in (P2-E4-T1, T3).
 *
 * Recorded by whoever opens the parcel at the counter, and it is the one thing
 * that decides whether the units go back on sale: goods in `good` condition
 * may be restocked, damaged goods may not. `ReceiveReturn` enforces that
 * rather than trusting the `restock` flag on its own — a tick box and a
 * condition that disagree would put broken goods back on the shelf.
 */
enum ReturnItemCondition: string
{
    case Good = 'good';
    case Damaged = 'damaged';

    public function label(): string
    {
        return match ($this) {
            self::Good => 'Baik / layak jual',
            self::Damaged => 'Rusak',
        };
    }

    /**
     * Whether units in this condition may go back on sale.
     */
    public function allowsRestock(): bool
    {
        return $this === self::Good;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $condition): array => ['value' => $condition->value, 'label' => $condition->label()],
            self::cases(),
        );
    }
}
