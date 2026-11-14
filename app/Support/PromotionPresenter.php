<?php

namespace App\Support;

use App\Models\Promotion;
use Illuminate\Support\Carbon;

/**
 * The shapes the promotion screens read (P2-E8-T2).
 *
 * Kept apart from the controller so the list and the form derive their figures
 * from one place: "berjalan" on the list and "aktif" in the form are the same
 * question, and this is where it is answered.
 */
final class PromotionPresenter
{
    /**
     * @return array{id: int, name: string, type: string, type_label: string, value: int, value_label: string, priority: int, products_count: int, branches_label: string, period_label: string, state_label: string, state_tone: string, is_active: bool}
     */
    public static function listRow(Promotion $promotion, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        [$stateLabel, $stateTone] = self::state($promotion, $now);

        return [
            'id' => $promotion->getKey(),
            'name' => $promotion->name,
            'type' => $promotion->type->value,
            'type_label' => $promotion->type->label(),
            'value' => $promotion->value,
            'value_label' => $promotion->type->value === 'percentage'
                ? "{$promotion->value}%"
                : Money::format($promotion->value),
            'priority' => $promotion->priority,
            'products_count' => $promotion->products_count ?? $promotion->products()->count(),
            'branches_label' => $promotion->branches->isEmpty()
                ? 'Semua cabang'
                : $promotion->branches->pluck('name')->implode(', '),
            'period_label' => self::period($promotion),
            'state_label' => $stateLabel,
            'state_tone' => $stateTone,
            'is_active' => (bool) $promotion->is_active,
        ];
    }

    /**
     * What the form needs to show an existing promotion.
     *
     * @return array{id: int, name: string, type: string, value: int, priority: int, starts_at: string|null, ends_at: string|null, is_active: bool, product_ids: array<int, int>, branch_ids: array<int, int>}
     */
    public static function detail(Promotion $promotion): array
    {
        return [
            'id' => $promotion->getKey(),
            'name' => $promotion->name,
            'type' => $promotion->type->value,
            'value' => $promotion->value,
            'priority' => $promotion->priority,
            'starts_at' => $promotion->starts_at?->toIso8601String(),
            'ends_at' => $promotion->ends_at?->toIso8601String(),
            'is_active' => (bool) $promotion->is_active,
            'product_ids' => $promotion->products->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'branch_ids' => $promotion->branches->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function state(Promotion $promotion, Carbon $now): array
    {
        return match (true) {
            ! $promotion->is_active => ['Nonaktif', 'neutral'],
            $promotion->starts_at !== null && $promotion->starts_at->greaterThan($now) => ['Dijadwalkan', 'info'],
            $promotion->ends_at !== null && $promotion->ends_at->lessThan($now) => ['Berakhir', 'neutral'],
            default => ['Berjalan', 'success'],
        };
    }

    private static function period(Promotion $promotion): string
    {
        if ($promotion->starts_at === null && $promotion->ends_at === null) {
            return 'Tanpa batas waktu';
        }

        $from = $promotion->starts_at?->format('d M Y H:i') ?? 'sekarang';
        $until = $promotion->ends_at?->format('d M Y H:i') ?? 'seterusnya';

        return "{$from} – {$until}";
    }
}
