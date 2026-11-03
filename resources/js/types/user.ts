import type { UserRole } from '@/types/auth';

/** The branch of a staff account, as the listing and forms receive it. */
export type StaffBranch = {
    id: number;
    name: string;
    code: string;
};

/** One row of the staff listing (`/admin/users`). */
export type StaffListItem = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    is_active: boolean;
    last_login_at: string | null;
    created_at: string | null;
    role: UserRole | null;
    role_label: string | null;
    branch: StaffBranch | null;
    is_self: boolean;
    can: {
        update: boolean;
        delete: boolean;
        toggle_active: boolean;
    };
};

/** The account being edited on `/admin/users/{user}/edit`. */
export type StaffDetail = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    is_active: boolean;
    branch_id: number | null;
    role: UserRole | null;
    last_login_at: string | null;
    created_at: string | null;
};

export type StaffRoleOption = {
    value: UserRole;
    label: string;
    requires_branch: boolean;
};

export type StaffBranchOption = {
    value: number;
    label: string;
};

/** Options for the create and edit forms, bounded by what the actor may grant. */
export type StaffFormOptions = {
    roles: StaffRoleOption[];
    branches: StaffBranchOption[];
    /** Set for a branch-bound actor: the only branch they may assign. */
    lockedBranchId: number | null;
};

export type StaffFilters = {
    search: string;
    role: string;
    branch: string;
    status: string;
    sort: string;
    direction: string;
};

export type StaffFilterOptions = {
    roles: Array<{ value: string; label: string }>;
    branches: Array<{ value: string; label: string }>;
    showBranchFilter: boolean;
};
