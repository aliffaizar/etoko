<?php

use App\Enums\UserRole;

/*
|--------------------------------------------------------------------------
| Role Based Access Control
|--------------------------------------------------------------------------
|
| The baseline permission catalogue and the permissions each role starts with.
| Roles and permissions themselves live in the database (spatie/laravel-permission)
| so a Super Admin can change them from the Role & Permission screen; this file
| is what `RolePermissionSeeder` installs and reconciles against.
|
| The matrix this mirrors is documented in docs/04-rbac.md.
|
*/

return [

    /*
     * Every permission the application knows about, grouped by domain. The group
     * key is only used to organise the management screen.
     */
    'permissions' => [
        'dashboard' => ['dashboard.central.view', 'dashboard.branch.view'],
        'products' => ['products.view', 'products.create', 'products.update', 'products.delete'],
        'categories' => ['categories.view', 'categories.create', 'categories.update', 'categories.delete'],
        'branch_prices' => ['branch_prices.view', 'branch_prices.manage'],
        'branches' => ['branches.view', 'branches.create', 'branches.update', 'branches.delete'],
        'warehouses' => ['warehouses.view', 'warehouses.manage'],
        'users' => ['users.view', 'users.create', 'users.update', 'users.delete'],
        'roles' => ['roles.view', 'roles.manage'],
        'customers' => ['customers.view', 'customers.update'],
        'stocks' => ['stocks.view', 'stocks.adjust'],
        'stock_entries' => ['stock_entries.view', 'stock_entries.create', 'stock_entries.post', 'stock_entries.delete'],
        'stock_issues' => ['stock_issues.view', 'stock_issues.create', 'stock_issues.post'],
        'stock_transfers' => [
            'stock_transfers.view', 'stock_transfers.request', 'stock_transfers.approve',
            'stock_transfers.ship', 'stock_transfers.receive',
        ],
        'stock_opnames' => ['stock_opnames.view', 'stock_opnames.create', 'stock_opnames.complete'],
        'orders' => ['orders.view', 'orders.update_status', 'orders.cancel', 'orders.assign_branch'],
        'payments' => ['payments.view', 'payments.verify', 'payments.refund'],
        'shipments' => ['shipments.view', 'shipments.manage'],
        'returns' => ['returns.view', 'returns.approve', 'refunds.process'],
        'marketing' => ['vouchers.manage', 'promotions.manage', 'flash_sales.manage', 'banners.manage', 'reviews.moderate'],
        'reports' => [
            'reports.sales.view', 'reports.stock.view', 'reports.product.view',
            'reports.customer.view', 'reports.branch.view', 'reports.export',
        ],
        'settings' => ['settings.store.manage', 'settings.payment.manage', 'settings.shipping.manage', 'settings.system.manage'],
        'audit' => ['audit_logs.view'],
        'pos' => ['pos.operate', 'pos.session.open', 'pos.session.close', 'pos.refund'],
    ],

    /*
     * Human readable names for the permission groups above, used as the section
     * headings of the Role & Permission screen (P1-E1-T5). A group without a
     * label here falls back to its key.
     */
    'permission_group_labels' => [
        'dashboard' => 'Dashboard',
        'products' => 'Produk',
        'categories' => 'Kategori',
        'branch_prices' => 'Harga Khusus Cabang',
        'branches' => 'Cabang',
        'warehouses' => 'Gudang',
        'users' => 'Pengguna',
        'roles' => 'Role & Permission',
        'customers' => 'Pelanggan',
        'stocks' => 'Stok',
        'stock_entries' => 'Barang Masuk',
        'stock_issues' => 'Barang Keluar',
        'stock_transfers' => 'Transfer Stok',
        'stock_opnames' => 'Stok Opname',
        'orders' => 'Pesanan',
        'payments' => 'Pembayaran',
        'shipments' => 'Pengiriman',
        'returns' => 'Retur & Refund',
        'marketing' => 'Marketing',
        'reports' => 'Laporan',
        'settings' => 'Pengaturan',
        'audit' => 'Audit Log',
        'pos' => 'POS / Kasir',
    ],

    /*
     * Permissions granted to each role on a fresh install. Super Admin is omitted
     * on purpose: it receives every permission, and also bypasses the gate.
     */
    'role_permissions' => [

        UserRole::AdminPusat->value => [
            'dashboard.central.view', 'dashboard.branch.view',
            'products.view', 'products.create', 'products.update', 'products.delete',
            'categories.view', 'categories.create', 'categories.update', 'categories.delete',
            'branch_prices.view', 'branch_prices.manage',
            'branches.view', 'branches.create', 'branches.update', 'branches.delete',
            'warehouses.view', 'warehouses.manage',
            'users.view', 'users.create', 'users.update', 'users.delete',
            'customers.view', 'customers.update',
            'stocks.view', 'stocks.adjust',
            'stock_entries.view', 'stock_entries.create', 'stock_entries.post', 'stock_entries.delete',
            'stock_issues.view', 'stock_issues.create', 'stock_issues.post',
            'stock_transfers.view', 'stock_transfers.request', 'stock_transfers.approve',
            'stock_transfers.ship', 'stock_transfers.receive',
            'stock_opnames.view', 'stock_opnames.create', 'stock_opnames.complete',
            'orders.view', 'orders.update_status', 'orders.cancel', 'orders.assign_branch',
            'payments.view', 'payments.verify', 'payments.refund',
            'shipments.view', 'shipments.manage',
            'returns.view', 'returns.approve', 'refunds.process',
            'vouchers.manage', 'promotions.manage', 'flash_sales.manage', 'banners.manage', 'reviews.moderate',
            'reports.sales.view', 'reports.stock.view', 'reports.product.view',
            'reports.customer.view', 'reports.branch.view', 'reports.export',
            'settings.store.manage', 'settings.payment.manage', 'settings.shipping.manage',
            'audit_logs.view',
        ],

        UserRole::AdminCabang->value => [
            'dashboard.branch.view',
            'products.view',
            'categories.view',
            'branch_prices.view',
            'branches.view', 'branches.update',
            'warehouses.view', 'warehouses.manage',
            'users.view', 'users.create', 'users.update',
            'customers.view',
            'stocks.view', 'stocks.adjust',
            'stock_entries.view', 'stock_entries.create', 'stock_entries.post',
            'stock_issues.view', 'stock_issues.create', 'stock_issues.post',
            'stock_transfers.view', 'stock_transfers.request', 'stock_transfers.approve',
            'stock_transfers.ship', 'stock_transfers.receive',
            'stock_opnames.view', 'stock_opnames.create', 'stock_opnames.complete',
            'orders.view', 'orders.update_status', 'orders.cancel',
            'payments.view', 'payments.verify',
            'shipments.view', 'shipments.manage',
            'returns.view', 'returns.approve',
            'reviews.moderate',
            'reports.sales.view', 'reports.stock.view', 'reports.product.view',
            'reports.customer.view', 'reports.branch.view', 'reports.export',
            'pos.operate',
        ],

        UserRole::StaffGudang->value => [
            'dashboard.branch.view',
            'products.view',
            'warehouses.view',
            'stocks.view', 'stocks.adjust',
            'stock_entries.view', 'stock_entries.create', 'stock_entries.post',
            'stock_issues.view', 'stock_issues.create', 'stock_issues.post',
            'stock_transfers.view', 'stock_transfers.request',
            'stock_opnames.view', 'stock_opnames.create', 'stock_opnames.complete',
            'reports.stock.view',
        ],

        UserRole::Kasir->value => [
            'dashboard.branch.view',
            'products.view',
            'customers.view',
            'stocks.view',
            'orders.view',
            'payments.view',
            'pos.operate', 'pos.session.open', 'pos.session.close', 'pos.refund',
        ],

        UserRole::Customer->value => [],
    ],
];
