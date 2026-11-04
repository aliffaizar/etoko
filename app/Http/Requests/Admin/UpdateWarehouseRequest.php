<?php

namespace App\Http\Requests\Admin;

use App\Models\Branch;
use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateWarehouseRequest extends FormRequest
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
                Rule::unique('warehouses', 'code')
                    ->where('branch_id', $branch->getKey())
                    ->ignore($this->warehouse()->getKey()),
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
     * Stock moves through the branch's default warehouse, so the flag can only
     * be handed to another warehouse — never simply dropped — and the warehouse
     * holding it cannot be deactivated.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $warehouse = $this->warehouse();

            if (! $warehouse->is_default) {
                return;
            }

            if (! $this->boolean('is_default')) {
                $validator->errors()->add(
                    'is_default',
                    'Tunjuk gudang lain sebagai gudang utama terlebih dahulu.',
                );
            }

            if (! $this->boolean('is_active')) {
                $validator->errors()->add(
                    'is_active',
                    'Gudang utama tidak boleh dinonaktifkan. Tunjuk gudang lain sebagai gudang utama terlebih dahulu.',
                );
            }
        }];
    }

    private function warehouse(): Warehouse
    {
        /** @var Warehouse $warehouse */
        $warehouse = $this->route('warehouse');

        return $warehouse;
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
