import type { StatusTone } from '@/components/panel/status-badge';
import type { FilterOption, Paginated } from './data-table';

/** A form's choice of branches or products, as `PickList` shows it. */
export type MarketingPickOption = {
    value: number;
    label: string;
};

export type BannerPositionValue = 'hero' | 'sidebar' | 'middle' | 'footer';

/** One row of `/admin/banners`. */
export type BannerListItem = {
    id: number;
    title: string;
    position_label: string;
    branch_label: string;
    period_label: string;
    state_label: string;
    state_tone: StatusTone;
    sort_order: number;
    is_active: boolean;
    image_url: string | null;
};

export type PaginatedBanners = Paginated<BannerListItem>;

/** The banner on `/admin/banners/{banner}/edit`. */
export type BannerDetail = {
    id: number;
    title: string;
    subtitle: string | null;
    link_url: string | null;
    position: BannerPositionValue;
    branch_id: number | null;
    sort_order: number;
    starts_at: string | null;
    ends_at: string | null;
    is_active: boolean;
    image_url: string | null;
    mobile_image_url: string | null;
};

export type BannerFormOptions = {
    positions: FilterOption[];
    branches: MarketingPickOption[];
};

/** One row of `/admin/promotions`. */
export type PromotionListItem = {
    id: number;
    name: string;
    type: string;
    type_label: string;
    value: number;
    value_label: string;
    priority: number;
    products_count: number;
    branches_label: string;
    period_label: string;
    state_label: string;
    state_tone: StatusTone;
    is_active: boolean;
};

export type PaginatedPromotions = Paginated<PromotionListItem>;

/** The promotion on `/admin/promotions/{promotion}/edit`. */
export type PromotionDetail = {
    id: number;
    name: string;
    type: string;
    value: number;
    priority: number;
    starts_at: string | null;
    ends_at: string | null;
    is_active: boolean;
    product_ids: number[];
    branch_ids: number[];
};

export type PromotionFormOptions = {
    types: FilterOption[];
    branches: MarketingPickOption[];
    products: MarketingPickOption[];
};

/** One row of `/admin/reviews`. */
export type ReviewAdminRow = {
    id: number;
    product_name: string;
    customer_name: string;
    branch_name: string;
    rating: number;
    title: string | null;
    comment: string | null;
    status: 'pending' | 'approved' | 'rejected';
    status_label: string;
    status_tone: StatusTone;
    verified: boolean;
    images: string[];
    reply: string | null;
    replied_by: string | null;
    created_at: string;
    can_approve: boolean;
    can_reject: boolean;
    can_reply: boolean;
};

export type PaginatedReviews = Paginated<ReviewAdminRow>;

/** One row of `/admin/featured-products`. */
export type FeaturedProductRow = {
    id: number;
    name: string;
    slug: string;
    price: number | null;
    is_active: boolean;
    is_published: boolean;
};
