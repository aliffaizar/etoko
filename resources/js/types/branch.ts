import type { UserRole } from '@/types/auth';

export type BranchStatus = 'active' | 'inactive' | 'maintenance';

/** One row of the branch listing (`/admin/branches`). */
export type BranchListItem = {
    id: number;
    code: string;
    name: string;
    city_name: string | null;
    province_name: string | null;
    phone: string | null;
    status: BranchStatus;
    status_label: string;
    supports_delivery: boolean;
    supports_pickup: boolean;
    is_default: boolean;
    sort_order: number;
    warehouse_count: number;
    staff_count: number;
    can: {
        update: boolean;
        delete: boolean;
    };
};

/** The branch being edited on `/admin/branches/{branch}/edit`. */
export type BranchDetail = {
    id: number;
    code: string;
    name: string;
    slug: string;
    description: string | null;
    phone: string | null;
    email: string | null;
    province_name: string | null;
    city_name: string | null;
    district_name: string | null;
    postal_code: string | null;
    address_line: string;
    latitude: string | null;
    longitude: string | null;
    status: BranchStatus;
    status_label: string;
    supports_delivery: boolean;
    supports_pickup: boolean;
    is_default: boolean;
    sort_order: number;
    created_at: string | null;
};

/** One of the seven rows of a branch's week (`day_of_week`: 0 = Sunday). */
export type BranchOperatingHour = {
    day_of_week: number;
    day_label: string;
    /** `HH:MM`, or null when the branch is closed that day. */
    opens_at: string | null;
    closes_at: string | null;
    is_closed: boolean;
};

export type BranchWarehouse = {
    id: number;
    code: string;
    name: string;
    address: string | null;
    is_default: boolean;
    is_active: boolean;
};

/** A staff account stationed at the branch. */
export type BranchStaffMember = {
    id: number;
    name: string;
    email: string;
    role: UserRole | null;
    role_label: string | null;
    is_active: boolean;
    last_login_at: string | null;
    can_update: boolean;
};

export type BranchStatusOption = {
    value: BranchStatus;
    label: string;
};

export type BranchFormOptions = {
    statuses: BranchStatusOption[];
};

/** Options the branch screen needs on top of the form's own. */
export type BranchScreenOptions = BranchFormOptions & {
    /** Branch-bound roles the actor may grant, for the staff dialog. */
    roles: Array<{ value: string; label: string }>;
};

/** A staff account of another branch that may be moved here. */
export type AssignableStaffOption = {
    value: number;
    label: string;
};

export type BranchAbilities = {
    update: boolean;
    delete: boolean;
    manage_warehouses: boolean;
    assign_staff: boolean;
    create_staff: boolean;
};

export type BranchFilters = {
    search: string;
    status: string;
    fulfillment: string;
    sort: string;
    direction: string;
};
