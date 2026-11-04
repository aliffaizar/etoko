<?php

namespace App\Concerns;

use App\Enums\BranchStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared validation for a branch's own details (P1-E2-T3).
 *
 * Create and edit submit the same fields, so the rules live here once. In
 * Phase 1 the address is captured as plain text: the `regions` dataset arrives
 * in `P2-E2-T3`, and the snapshot columns are what the storefront and shipping
 * quotes read anyway (docs/03-data-model.md).
 *
 * @phpstan-require-extends FormRequest
 */
trait ValidatesBranchDetails
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function branchDetailRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'email', 'max:255'],

            'province_name' => ['required', 'string', 'max:255'],
            'city_name' => ['required', 'string', 'max:255'],
            'district_name' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'address_line' => ['required', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],

            'status' => ['required', Rule::enum(BranchStatus::class)],
            'supports_delivery' => ['boolean'],
            'supports_pickup' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function branchDetailAttributes(): array
    {
        return [
            'code' => 'kode cabang',
            'name' => 'nama cabang',
            'slug' => 'slug',
            'description' => 'deskripsi',
            'phone' => 'nomor telepon',
            'email' => 'email',
            'province_name' => 'provinsi',
            'city_name' => 'kota/kabupaten',
            'district_name' => 'kecamatan',
            'postal_code' => 'kode pos',
            'address_line' => 'alamat',
            'latitude' => 'latitude',
            'longitude' => 'longitude',
            'status' => 'status',
            'supports_delivery' => 'dukungan pengiriman',
            'supports_pickup' => 'dukungan ambil di cabang',
            'is_default' => 'cabang utama',
            'sort_order' => 'urutan tampil',
        ];
    }

    /**
     * A branch that supports neither delivery nor pickup cannot fulfil an order
     * at all, so it is refused rather than saved as an unusable branch.
     */
    protected function requireAtLeastOneFulfillmentType(Validator $validator): void
    {
        if (! $this->boolean('supports_delivery') && ! $this->boolean('supports_pickup')) {
            $validator->errors()->add(
                'supports_delivery',
                'Cabang harus mendukung minimal satu cara pemenuhan: pengiriman atau ambil di cabang.',
            );
        }
    }

    protected function selectedStatus(): ?BranchStatus
    {
        return BranchStatus::tryFrom((string) $this->input('status'));
    }

    /**
     * `sort_order` is NOT NULL in the database, but an emptied number input
     * arrives as null (Laravel converts empty strings), so it is read as "no
     * preference" and normalised to the column default rather than refused.
     */
    protected function normalizeSortOrder(): void
    {
        if ($this->input('sort_order') === null || $this->input('sort_order') === '') {
            $this->merge(['sort_order' => 0]);
        }
    }
}
