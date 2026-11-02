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
        return array_map(
            fn (self $role): array => ['value' => $role->value, 'label' => $role->label()],
            self::cases(),
        );
    }
}
