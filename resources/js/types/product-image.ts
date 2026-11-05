/** One photo in a product's gallery (`/admin/products/{product}/images`). */
export type ProductImageItem = {
    id: number;
    /** Public URL of the stored file. */
    url: string;
    path: string;
    alt_text: string | null;
    sort_order: number;
    is_primary: boolean;
    /** Set when the photo shows one specific variant. */
    product_variant_id: number | null;
    /** The variant's name or SKU, for the chip on the card. */
    variant_label: string | null;
    can: {
        update: boolean;
        delete: boolean;
        make_primary: boolean;
    };
};

/** The product the gallery belongs to. */
export type ProductImageProductSummary = {
    id: number;
    name: string;
    type_label: string;
};

export type ProductImageScreenOptions = {
    variants: { value: number; label: string }[];
};

/** Upload limits the server enforces, mirrored so the UI can say them up front. */
export type ProductImageLimits = {
    max_files: number;
    max_kilobytes: number;
    min_dimension: number;
};
