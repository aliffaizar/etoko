import type { FilterOption } from '@/types/data-table';

/** How an attribute is presented when a buyer picks a variant. */
export type ProductAttributeType = 'select' | 'color';

/** One option of an attribute, such as Merah under Warna. */
export type ProductAttributeValueItem = {
    id: number;
    value: string;
    label: string | null;
    /** `label` when one was given, `value` otherwise. */
    display_label: string;
    /** Lower-case six-digit hex; only set on a `color` attribute. */
    color_hex: string | null;
    sort_order: number;
    /** How many variants across the catalogue this value defines. */
    variant_count: number;
};

/** One attribute with its values (`/admin/product-attributes`). */
export type ProductAttributeItem = {
    id: number;
    name: string;
    slug: string;
    type: ProductAttributeType;
    type_label: string;
    uses_color_swatch: boolean;
    sort_order: number;
    /** Variants across the catalogue defined by any of this attribute's values. */
    variant_usage_count: number;
    /** Why the attribute may not be deleted, or null when it may. */
    deletion_blocked_reason: string | null;
    values: ProductAttributeValueItem[];
    can: {
        update: boolean;
        delete: boolean;
    };
};

export type ProductAttributeScreenOptions = {
    types: FilterOption[];
};
