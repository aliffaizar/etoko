<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Order;
use App\Models\User;
use App\Support\OrderPresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer listing for the panel (P1-E6-T7, order figures added in P1-E7-T8).
 *
 * Customers are always `role = customer` with no `branch_id`. This screen is
 * separate from the staff user screen because the actors are different (panel
 * staff vs. shoppers) and because what you want to know about them is
 * different: a staff account has a role and a branch, a customer has orders.
 *
 * `Order` carries `BelongsToBranch`, so the order counts and totals below are
 * **already narrowed to the actor's branch** (BR-3): a branch admin sees what
 * that customer has bought from *them*, which is what a branch can answer for.
 * Central sees the whole figure.
 *
 * Permission: `customers.view` (read-only listing and profile detail).
 */
class CustomerController extends Controller
{
    private const PER_PAGE = 15;

    private const RECENT_ORDERS = 10;

    /**
     * Columns the listing may be sorted by, as `sort` value => column.
     *
     * @var array<string, string>
     */
    private const SORTS = [
        'name' => 'name',
        'created_at' => 'created_at',
    ];

    public function index(Request $request): Response
    {
        $this->authorize('customers.view');

        $filters = $this->validatedFilters($request);

        return Inertia::render('admin/customers/index', [
            'customers' => $this->paginated($filters),
            'filters' => $filters,
            'options' => [
                'statuses' => [
                    ['value' => 'active', 'label' => 'Aktif'],
                    ['value' => 'inactive', 'label' => 'Nonaktif'],
                ],
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
                'order_count' => $customer->orders()->count(),
                // Only settled money counts as "spent": an unpaid order is a
                // hope, and counting it would overstate every customer.
                'total_spend' => (int) $customer->orders()
                    ->where('payment_status', PaymentStatus::Paid)
                    ->sum('grand_total'),
            ],
            'orders' => $this->recentOrders($customer),
        ]);
    }

    /**
     * @return array{search: string, status: string, sort: string, direction: string}
     */
    private function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'sort' => ['nullable', 'string', Rule::in(array_keys(self::SORTS))],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return [
            'search' => trim((string) ($validated['search'] ?? '')),
            'status' => (string) ($validated['status'] ?? ''),
            'sort' => (string) ($validated['sort'] ?? 'created_at'),
            'direction' => (string) ($validated['direction'] ?? 'desc'),
        ];
    }

    /**
     * @param  array{search: string, status: string, sort: string, direction: string}  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginated(array $filters): LengthAwarePaginator
    {
        $column = self::SORTS[$filters['sort']] ?? 'created_at';
        $direction = $filters['direction'] === 'asc' ? 'asc' : 'desc';

        return User::query()
            ->role(UserRole::Customer->value)
            ->withCount('orders')
            ->when($filters['search'] !== '', fn (Builder $q) => $q->where(
                fn (Builder $match) => $match
                    ->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('email', 'like', "%{$filters['search']}%")
                    ->orWhere('phone', 'like', "%{$filters['search']}%"),
            ))
            ->when($filters['status'] === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->orderBy($column, $direction)
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_active' => $user->is_active,
                'order_count' => (int) ($user->getAttribute('orders_count') ?? 0),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ]);
    }

    /**
     * The customer's most recent orders, in the same shape the order listing
     * uses — so the row reads the same here as it does there.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentOrders(User $customer): array
    {
        return $customer->orders()
            ->with(['branch:id,name'])
            ->withSum('items', 'quantity')
            ->latestFirst()
            ->limit(self::RECENT_ORDERS)
            ->get()
            ->map(fn (Order $order): array => OrderPresenter::row($order))
            ->values()
            ->all();
    }
}
