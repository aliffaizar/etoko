<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case AdminPusat = 'admin_pusat';
    case AdminCabang = 'admin_cabang';
    case StaffGudang = 'staff_gudang';
    case Kasir = 'kasir';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::AdminPusat => 'Admin Pusat',
            self::AdminCabang => 'Admin Cabang',
            self::StaffGudang => 'Staff Gudang',
            self::Kasir => 'Kasir',
            self::Customer => 'Customer',
        };
    }

    /**
     * Roles that operate across every branch and therefore bypass the branch scope.
     */
    public function isCentral(): bool
    {
        return in_array($this, [self::SuperAdmin, self::AdminPusat], strict: true);
    }

    /**
     * Roles whose users must be assigned to exactly one branch.
     */
    public function requiresBranch(): bool
    {
        return in_array($this, [self::AdminCabang, self::StaffGudang, self::Kasir], strict: true);
    }

    /**
     * The route name users with this role land on after signing in.
     */
    public function homeRouteName(): string
    {
        return match ($this) {
            self::SuperAdmin, self::AdminPusat => 'admin.dashboard',
            self::AdminCabang, self::StaffGudang => 'branch.dashboard',
            self::Kasir => 'pos.index',
            self::Customer => 'home',
        };
    }

    /**
     * Whether a URL path lies in an area this role may enter.
     *
     * Only the leading panel segment is weighed; the routes themselves keep
     * their own permission checks (`can:` middleware and policies). This is a
     * safety net for redirects, so a saved "intended" URL never overrides the
     * role's landing page with a page the role cannot open at all.
     *
     * A branch admin manages their own staff at `/admin/users`, which is why
     * the central prefix is open to them (docs/04-rbac.md).
     */
    public function mayEnter(string $path): bool
    {
        if ($this->isCentral()) {
            return true;
        }

        return match (Str::before(ltrim($path, '/'), '/')) {
            'admin' => $this === self::AdminCabang,
            'branch', 'pos' => $this->requiresBranch(),
            default => true,
        };
    }

    /**
     * Staff roles a user holding this role may grant to someone else.
     *
     * A Super Admin is the only role that can create another Super Admin, and a
     * branch role may only ever create branch roles — that keeps a branch admin
     * from promoting themselves out of their own branch (docs/04-rbac.md).
     *
     * @return array<int, self>
     */
    public function grantableRoles(): array
    {
        return match ($this) {
            self::SuperAdmin => self::staffRoles(),
            self::AdminPusat => [self::AdminPusat, self::AdminCabang, self::StaffGudang, self::Kasir],
            self::AdminCabang => [self::AdminCabang, self::StaffGudang, self::Kasir],
            self::StaffGudang, self::Kasir, self::Customer => [],
        };
    }

    /**
     * Roles that may sign in to a management panel.
     *
     * @return array<int, self>
     */
    public static function staffRoles(): array
    {
        return [self::SuperAdmin, self::AdminPusat, self::AdminCabang, self::StaffGudang, self::Kasir];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return self::optionsFor(self::cases());
    }

    /**
     * Select options for a specific set of roles, in the order given.
     *
     * @param  array<int, self>  $roles
     * @return array<int, array{value: string, label: string}>
     */
    public static function optionsFor(array $roles): array
    {
        return array_values(array_map(
            fn (self $role): array => ['value' => $role->value, 'label' => $role->label()],
            $roles,
        ));
    }

    /**
     * @return array<int, string>
     */
    public static function staffRoleValues(): array
    {
        return array_map(fn (self $role): string => $role->value, self::staffRoles());
    }
}
