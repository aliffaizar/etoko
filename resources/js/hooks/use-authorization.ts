import { usePage } from '@inertiajs/react';
import type { Auth, UserRole } from '@/types/auth';

export type UseAuthorizationReturn = {
    auth: Auth;
    /** Whether the signed-in user holds the given permission. */
    can: (permission: string) => boolean;
    /** Whether the user holds at least one of the given permissions. */
    canAny: (permissions: string[]) => boolean;
    hasRole: (role: UserRole) => boolean;
};

/**
 * Reads the permissions shared by HandleInertiaRequests.
 *
 * This only decides what the interface offers. Every route is still guarded
 * server side by its own permission and policy check.
 */
export function useAuthorization(): UseAuthorizationReturn {
    const auth = usePage().props.auth as Auth;
    const permissions = auth?.permissions ?? [];

    const can = (permission: string): boolean =>
        permissions.includes(permission);

    return {
        auth,
        can,
        canAny: (candidates: string[]): boolean => candidates.some(can),
        hasRole: (role: UserRole): boolean => auth?.role === role,
    };
}
