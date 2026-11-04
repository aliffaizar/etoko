<?php

namespace App\Http\Requests\Admin;

use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adds a warehouse to a branch (P1-E2-T6).
 *
 * The code only has to be unique inside its branch, so two branches may both
 * call their main warehouse `WH-01`.
 */
class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Branch $branch */
        $branch = $this->route('branch');

        return [
            'code' => [
                'required', 'string', 'max:30', 'regex:/^[A-Z0-9-]+$/',
                Rule::unique('warehouses', 'code')->where('branch_id', $branch->getKey()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => mb_strtoupper(trim((string) $this->input('code')))]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode gudang',
            'name' => 'nama gudang',
            'address' => 'alamat gudang',
            'is_default' => 'gudang utama',
            'is_active' => 'status aktif',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode gudang hanya boleh berisi huruf kapital, angka, dan tanda hubung (mis. WH-01).',
            'code.unique' => 'Kode gudang ini sudah dipakai di cabang yang sama.',
        ];
    }
}
