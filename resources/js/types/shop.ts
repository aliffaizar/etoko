import type { FilterOption } from '@/types/data-table';

/**
 * Where a resolved price came from, mirroring `App\Services\Pricing\PriceSource`.
 *
 * The storefront charges the catalogue price everywhere (ADR-031), so this is
 * only worth showing when something *beats* it — a flash sale or a promotion
 * in `P1-E8`. A plain `variant_price` or `product_base_price` needs no label.
 */
export type PriceSource =
    | 'flash_sale'
    | 'promotion'
    | 'branch_price'
    | 'variant_price'
    | 'product_base_price';

/** A branch as the storefront header and picker show it. */
export type ShopBranch = {
    id: number;
    name: string;
    code: string;
    city_name: string | null;
    address_line: string;
    supports_delivery: boolean;
    supports_pickup: boolean;
};

/** A branch in the picker, which also says whether it can be ranked by distance. */
export type ShopBranchOption = ShopBranch & {
    has_coordinates: boolean;
};

/** One entry of the header's category menu: a root and its children. */
export type ShopCategoryMenuItem = {
    name: string;
    slug: string;
    icon: string | null;
    children: Array<{ name: string; slug: string }>;
};

/**
 * Props every storefront page shares, from `ShareStorefrontContext`.
 *
 * `branch` is null only on a fresh installation with no branch at all; the
 * screens never render in that state because the controllers answer 503.
 */
export type ShopContext = {
    branch: ShopBranch | null;
    branches: ShopBranchOption[];
    /** False while the visitor is being shown the main branch by default. */
    has_chosen_branch: boolean;
    categories: ShopCategoryMenuItem[];
};

/**
 * One product as a card, from `App\Support\ProductCardPresenter`.
 *
 * Two figures, two owners (ADR-031): `price` is the catalogue's and is the same
 * at every branch, while `available_quantity` and `is_sold_at_branch` belong to
 * the branch in the session.
 */
export type ShopProductCard = {
    id: number;
    name: string;
    slug: string;
    short_description: string | null;
    category_name: string;
    category_slug: string;
    image_url: string | null;
    image_alt: string;
    /** Catalogue price in whole rupiah (ADR-008); null only when no variant is left. */
    price: number | null;
    compare_at_price: number | null;
    price_source: PriceSource | null;
    /** True when the options are not all the same price, i.e. "mulai dari". */
    has_price_range: boolean;
    variant_count: number;
    available_quantity: number;
    is_in_stock: boolean;
    /**
     * False when the chosen branch has said it does not stock this product.
     * Different from `is_in_stock === false`, which means "sold out for now".
     */
    is_sold_at_branch: boolean;
    is_featured: boolean;
    rating_avg: number;
    rating_count: number;
    sold_count: number;
};

/** A promotional slide on the front page; static until Phase 2 (`banners.manage`). */
export type ShopBanner = {
    title: string;
    subtitle: string;
    cta_label: string;
    tone: 'primary' | 'accent' | 'muted';
};

/** A top-level category as the front page shows it. */
export type ShopCategoryTile = {
    name: string;
    slug: string;
    icon: string | null;
    image_url: string | null;
    child_names: string[];
};

/** The catalogue's query string, normalised by `IndexCatalogRequest`. */
export type CatalogFilters = {
    search: string;
    category: string;
    min_price: string;
    max_price: string;
    availability: string;
    sort: string;
};

/** A category option of the catalogue filter, indented by `depth`. */
export type CatalogCategoryOption = {
    value: string;
    label: string;
    depth: number;
};

export type CatalogOptions = {
    categories: CatalogCategoryOption[];
    sorts: FilterOption[];
    availability: FilterOption[];
};

/** The category a shopper entered the catalogue through. */
export type CatalogCategoryHeading = {
    name: string;
    slug: string;
    description: string | null;
    /** Ancestors, nearest root first; empty for a top-level category. */
    path: Array<{ name: string; slug: string }>;
};

/** The product shown on `/produk/{slug}`. */
export type ShopProductDetail = {
    id: number;
    name: string;
    slug: string;
    type: 'simple' | 'variable';
    short_description: string | null;
    description: string | null;
    category_name: string;
    category_slug: string;
    weight_gram: number;
    length_cm: number | null;
    width_cm: number | null;
    height_cm: number | null;
    rating_avg: number;
    rating_count: number;
    sold_count: number;
    is_featured: boolean;
    /** The cheapest and dearest option, for a "mulai dari" heading. */
    price_from: number | null;
    price_to: number | null;
    meta_title: string | null;
    meta_description: string | null;
};

/** One sellable option: catalogue price, stock from the chosen branch. */
export type ShopProductVariant = {
    id: number;
    sku: string;
    name: string;
    price: number;
    compare_at_price: number | null;
    discount_amount: number | null;
    price_source: PriceSource;
    price_source_label: string;
    available_quantity: number;
    is_in_stock: boolean;
    weight_gram: number;
    image_url: string | null;
    /** Attribute value ids, which is what the option picker matches on. */
    attribute_value_ids: number[];
};

export type ShopProductImage = {
    id: number;
    url: string;
    alt: string;
    is_primary: boolean;
    /** Set when the photo shows one option, so picking it swaps the gallery. */
    variant_id: number | null;
};

/** One option group of the variant picker, e.g. Warna or Ukuran. */
export type ShopProductAttribute = {
    id: number;
    name: string;
    type: 'select' | 'color';
    values: Array<{ id: number; label: string; color_hex: string | null }>;
};

/** What the chosen branch holds of the product as a whole. */
export type ShopProductAvailability = {
    available_quantity: number;
    is_in_stock: boolean;
    /** False when the branch does not stock this product at all (ADR-031). */
    is_sold_at_branch: boolean;
    variant_count: number;
    in_stock_variant_count: number;
};
