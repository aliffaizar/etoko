<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreAddressRequest;
use App\Http\Requests\Shop\UpdateAddressRequest;
use App\Models\Address;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer delivery address book (P1-E6-T3).
 *
 * Exactly one address per user may be `is_default`. Setting a new default
 * clears the flag on the previous one inside a transaction.
 *
 * FK columns (province_id etc.) are nullable — the regions dataset arrives in
 * Phase 2 (P2-E2-T3). Until then addresses accept snapshot strings only.
 */
class AddressController extends Controller
{
    public function index(): Response
    {
        $addresses = auth()->user()->addresses()->orderByDesc('is_default')->orderBy('created_at')->get();

        return Inertia::render('shop/addresses', [
            'addresses' => $addresses->map(fn (Address $a): array => $this->addressProps($a))->values()->all(),
        ]);
    }

    public function store(StoreAddressRequest $request): RedirectResponse
    {
        $user = auth()->user();
        $data = $request->validated();

        DB::transaction(function () use ($user, $data) {
            if ($data['is_default'] ?? false) {
                $user->addresses()->update(['is_default' => false]);
            }

            $user->addresses()->create($data);

            if ($user->addresses()->count() === 1) {
                $user->addresses()->update(['is_default' => true]);
            }
        });

        return redirect()->route('shop.account.addresses.index')->with('success', 'Alamat berhasil ditambahkan.');
    }

    public function update(UpdateAddressRequest $request, Address $address): RedirectResponse
    {
        $this->authorizeAddress($address);

        $data = $request->validated();

        DB::transaction(function () use ($address, $data) {
            if ($data['is_default'] ?? false) {
                auth()->user()->addresses()->where('id', '!=', $address->id)->update(['is_default' => false]);
            }

            $address->update($data);

            // If this address is no longer default, promote the next oldest one
            if (! ($data['is_default'] ?? false) && auth()->user()->addresses()->where('is_default', true)->doesntExist()) {
                auth()->user()->addresses()->where('id', '!=', $address->id)->oldest()->limit(1)->update(['is_default' => true]);
            }
        });

        return redirect()->route('shop.account.addresses.index')->with('success', 'Alamat berhasil diperbarui.');
    }

    public function destroy(Address $address): RedirectResponse
    {
        $this->authorizeAddress($address);

        $wasDefault = $address->is_default;
        $address->delete();

        // If the deleted address was the default, promote the first remaining one
        if ($wasDefault) {
            auth()->user()->addresses()->oldest()->limit(1)->update(['is_default' => true]);
        }

        return redirect()->route('shop.account.addresses.index')->with('success', 'Alamat berhasil dihapus.');
    }

    /**
     * Refuse somebody else's address with a 404, not a 403 (LOW-02).
     *
     * The same choice orders make (ADR-027): answering "forbidden" would
     * confirm that the id exists and belongs to another customer, which is one
     * more thing than an outsider should be able to learn by guessing.
     */
    private function authorizeAddress(Address $address): void
    {
        abort_unless($address->user_id === auth()->id(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function addressProps(Address $address): array
    {
        return [
            'id' => $address->id,
            'label' => $address->label,
            'recipient_name' => $address->recipient_name,
            'phone' => $address->phone,
            'province_name' => $address->province_name,
            'city_name' => $address->city_name,
            'district_name' => $address->district_name,
            'postal_code' => $address->postal_code,
            'address_line' => $address->address_line,
            'is_default' => $address->is_default,
            'notes' => $address->notes,
        ];
    }
}
