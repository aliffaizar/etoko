<?php

namespace App\Enums;

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
