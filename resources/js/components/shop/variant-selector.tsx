import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { ShopProductAttribute, ShopProductVariant } from '@/types';

type Props = {
    attributes: ShopProductAttribute[];
    variants: ShopProductVariant[];
    /** Attribute id => chosen value id. */
    selection: Record<number, number>;
    onSelect: (attributeId: number, valueId: number) => void;
};

/**
 * The option picker of a variable product (P1-E5-T6).
 *
 * A value is **offered** when some variant carries it, and **reachable** when
 * some variant carries it together with everything already chosen in the
 * *other* groups. An unreachable value is shown struck through rather than
 * hidden: a shopper who wants XL should see that XL exists and that this
 * colour is what rules it out, not watch the row of sizes change length as
 * they click around.
 *
 * Reachability is not availability. A value that leads to a real variant with
 * no stock left stays selectable — the stock is said out loud next to the
 * price once it is picked, which is a clearer answer than an option that
 * cannot be pressed for a reason the shopper has to guess.
 */
export function VariantSelector({
    attributes,
    variants,
    selection,
    onSelect,
}: Props) {
    const isReachable = (attributeId: number, valueId: number): boolean => {
        const otherChoices = Object.entries(selection)
            .filter(
                ([chosenAttributeId]) =>
                    Number(chosenAttributeId) !== attributeId,
            )
            .map(([, chosenValueId]) => chosenValueId);

        return variants.some(
            (variant) =>
                variant.attribute_value_ids.includes(valueId) &&
                otherChoices.every((choice) =>
                    variant.attribute_value_ids.includes(choice),
                ),
        );
    };

    return (
        <div className="flex flex-col gap-4">
            {attributes.map((attribute) => (
                <fieldset key={attribute.id} className="flex flex-col gap-2">
                    <legend className="text-xs text-muted-foreground">
                        {attribute.name}
                    </legend>

                    <div className="flex flex-wrap gap-2">
                        {attribute.values.map((value) => {
                            const isSelected =
                                selection[attribute.id] === value.id;
                            const reachable = isReachable(
                                attribute.id,
                                value.id,
                            );

                            return (
                                <button
                                    key={value.id}
                                    type="button"
                                    onClick={() =>
                                        onSelect(attribute.id, value.id)
                                    }
                                    aria-pressed={isSelected}
                                    title={
                                        reachable
                                            ? undefined
                                            : 'Kombinasi ini tidak tersedia'
                                    }
                                    className={cn(
                                        'inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm transition-colors',
                                        isSelected
                                            ? 'border-primary bg-primary/5 font-medium'
                                            : 'hover:border-primary/40 hover:bg-accent',
                                        !reachable &&
                                            'text-muted-foreground line-through opacity-60',
                                    )}
                                >
                                    {attribute.type === 'color' &&
                                        value.color_hex && (
                                            <span
                                                aria-hidden
                                                className="size-3.5 rounded-full border"
                                                style={{
                                                    backgroundColor:
                                                        value.color_hex,
                                                }}
                                            />
                                        )}
                                    {value.label}
                                    {isSelected && (
                                        <Check
                                            className="size-3.5 text-primary"
                                            aria-hidden
                                        />
                                    )}
                                </button>
                            );
                        })}
                    </div>
                </fieldset>
            ))}
        </div>
    );
}

/**
 * The variant matching a complete selection, or null while a group is still
 * unanswered or the combination does not exist.
 *
 * Exported with the picker because the two share one definition of a match:
 * the page reads the price and the stock of whatever this returns.
 */
export function findVariant(
    variants: ShopProductVariant[],
    attributes: ShopProductAttribute[],
    selection: Record<number, number>,
): ShopProductVariant | null {
    // A product with no options has exactly one variant (ADR-020), and it is
    // the one being bought.
    if (attributes.length === 0) {
        return variants[0] ?? null;
    }

    if (Object.keys(selection).length !== attributes.length) {
        return null;
    }

    const chosen = Object.values(selection);

    return (
        variants.find(
            (variant) =>
                variant.attribute_value_ids.length === chosen.length &&
                chosen.every((valueId) =>
                    variant.attribute_value_ids.includes(valueId),
                ),
        ) ?? null
    );
}

/**
 * The options of the variant a shopper lands on, so the page opens with a
 * price and a stock figure rather than with "pilih varian".
 *
 * The default variant comes first from the server (ADR-020), which makes it
 * the one chosen here.
 */
export function initialSelection(
    variants: ShopProductVariant[],
    attributes: ShopProductAttribute[],
): Record<number, number> {
    const first = variants[0];

    if (!first || attributes.length === 0) {
        return {};
    }

    const selection: Record<number, number> = {};

    for (const attribute of attributes) {
        const match = attribute.values.find((value) =>
            first.attribute_value_ids.includes(value.id),
        );

        if (match) {
            selection[attribute.id] = match.id;
        }
    }

    return selection;
}
