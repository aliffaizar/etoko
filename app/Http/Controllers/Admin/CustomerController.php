<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer listing for the admin panel (P1-E6-T7).
 *
 * Customers are always `role = customer` with no `branch_id`. This screen is
 * separate from the staff user screen because the actors are different (panel
 * staff vs. shoppers) and because admin cabang is scoped to their branch's
 * customers — those who have placed an order at that branch (P1-E7 wires
 * the order side; for now every customer is visible).
 *
 * Permission: `customers.view` (read-only listing and profile detail).
 */
class CustomerController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): Response
    {
        $this->authorize('customers.view');

        $search = $request->string('search')->toString();
        $status = $request->string('status')->toString();

        $query = User::query()
            ->role(UserRole::Customer->value)
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            }))
            ->when($status === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->orderByDesc('created_at');

        $customers = $query->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('admin/customers/index', [
            'customers' => [
                'data' => collect($customers->items())->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'is_active' => $user->is_active,
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                    'created_at' => $user->created_at?->toIso8601String(),
                ])->values()->all(),
                'meta' => [
                    'total' => $customers->total(),
                    'per_page' => $customers->perPage(),
                    'current_page' => $customers->currentPage(),
                    'last_page' => $customers->lastPage(),
                    'from' => $customers->firstItem(),
                    'to' => $customers->lastItem(),
                ],
                'links' => [
                    'prev' => $customers->previousPageUrl(),
                    'next' => $customers->nextPageUrl(),
                ],
            ],
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
        ]);
    }

    public function show(User $customer): Response
    {
        $this->authorize('customers.view');

        abort_unless($customer->hasRole(UserRole::Customer->value), 404);

        $customer->load(['addresses' => fn ($q) => $q->orderByDesc('is_default')->orderBy('created_at')]);

        return Inertia::render('admin/customers/show', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'avatar_path' => $customer->avatar_path,
                'is_active' => $customer->is_active,
                'email_verified_at' => $customer->email_verified_at?->toIso8601String(),
                'last_login_at' => $customer->last_login_at?->toIso8601String(),
                'created_at' => $customer->created_at?->toIso8601String(),
                'addresses' => $customer->addresses->map(fn (Address $a): array => [
                    'id' => $a->id,
                    'label' => $a->label,
                    'recipient_name' => $a->recipient_name,
                    'city_name' => $a->city_name,
                    'province_name' => $a->province_name,
                    'is_default' => $a->is_default,
                ])->values()->all(),
                // Orders will be populated in P1-E7-T8 when the orders table exists
                'order_count' => 0,
                'total_spend' => 0,
            ],
        ]);
    }
}
