import type { ProductType } from '@/types/product';

/** One option in force on a variant, ready to render as a chip. */
export type ProductVariantOption = {
    attribute_id: number;
    attribute_name: string;
    value_id: number;
    label: string;
    color_hex: string | null;
};

/**
 * One variant on the editor (`/admin/products/{product}/variants`).
 *
 * The `price`, `weight_gram` and dimension fields are overrides: null means
 * "follow the product" (ADR-020). The `effective_*` fields are what is
 * actually in force, worked out server-side so the screen never re-derives the
 * fallback rule.
 */
export type ProductVariantItem = {
    id: number;
    sku: string;
    barcode: string | null;
    name: string | null;
    /** `name`, or the product's name for a variant without options. */
    display_name: string;
    price: number | null;
    compare_at_price: number | null;
    cost_price: number | null;
    weight_gram: number | null;
    length_cm: number | null;
    width_cm: number | null;
    height_cm: number | null;
    effective_price: number;
    effective_compare_at_price: number | null;
    effective_weight_gram: number;
    is_active: boolean;
    is_default: boolean;
    attribute_value_ids: number[];
    options: ProductVariantOption[];
    can: {
        update: boolean;
        delete: boolean;
        make_default: boolean;
    };
    /** Why the variant may not be deleted, or null when it may. */
    deletion_blocked_reason: string | null;
};

/** The product the editor belongs to, with the figures variants fall back to. */
export type ProductVariantSummary = {
    id: number;
    name: string;
    type: ProductType;
    type_label: string;
    base_price: number;
    compare_at_price: number | null;
    cost_price: number | null;
    weight_gram: number;
    length_cm: number | null;
    width_cm: number | null;
    height_cm: number | null;
};

/** One value offered by the generator's pickers. */
export type VariantGeneratorValue = {
    id: number;
    label: string;
    color_hex: string | null;
};

/** One attribute offered by the generator's pickers. */
export type VariantGeneratorAttribute = {
    id: number;
    name: string;
    type: string;
    uses_color_swatch: boolean;
    values: VariantGeneratorValue[];
};

export type ProductVariantScreenOptions = {
    attributes: VariantGeneratorAttribute[];
};

export type ProductVariantAbilities = {
    update: boolean;
    /** False for a `simple` product, whose single variant is the whole product. */
    generate: boolean;
};

export type ProductVariantLimits = {
    max_combinations: number;
};
