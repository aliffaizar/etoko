import type { CategoryParentOption } from '@/types/category';
import type { FilterOption } from '@/types/data-table';

export type ProductType = 'simple' | 'variable';

/**
 * Where a product stands towards buyers, derived server-side from `is_active`
 * and `published_at` together.
 */
export type ProductPublicationState =
    | 'inactive'
    | 'draft'
    | 'scheduled'
    | 'published';

/** One row of the product listing (`/admin/products`). */
export type ProductListItem = {
    id: number;
    name: string;
    slug: string;
    /** SKU of the product's default variant; null only for broken data. */
    sku: string | null;
    category_name: string;
    type: ProductType;
    type_label: string;
    /** Whole rupiah (ADR-008). */
    base_price: number;
    compare_at_price: number | null;
    weight_gram: number;
    is_active: boolean;
    is_featured: boolean;
    state: ProductPublicationState;
    state_label: string;
    published_at: string | null;
    variant_count: number;
    sold_count: number;
    can: {
        update: boolean;
        delete: boolean;
    };
};

/** The product being edited on `/admin/products/{product}/edit`. */
export type ProductDetail = {
    id: number;
    category_id: number;
    category_name: string;
    name: string;
    slug: string;
    type: ProductType;
    short_description: string | null;
    description: string | null;
    base_price: number;
    compare_at_price: number | null;
    cost_price: number | null;
    weight_gram: number;
    length_cm: number | null;
    width_cm: number | null;
    height_cm: number | null;
    is_active: boolean;
    is_featured: boolean;
    /** `YYYY-MM-DDTHH:MM` for a `datetime-local` input, or null for a draft. */
    published_at: string | null;
    meta_title: string | null;
    meta_description: string | null;
    /** The default variant's SKU and barcode, which this screen owns (ADR-020). */
    sku: string;
    barcode: string | null;
    state: ProductPublicationState;
    state_label: string;
    variant_count: number;
    sold_count: number;
    created_at: string | null;
};

/** Options the product form needs. Categories are indented by `depth`. */
export type ProductFormOptions = {
    categories: CategoryParentOption[];
    types: FilterOption[];
};

/** The form's options plus the listing's own filters. */
export type ProductScreenOptions = ProductFormOptions & {
    states: FilterOption[];
};

export type ProductAbilities = {
    update: boolean;
    delete: boolean;
};
