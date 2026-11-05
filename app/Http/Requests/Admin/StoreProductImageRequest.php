<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Photos being uploaded to a product's gallery (P1-E3-T7).
 *
 * `images` is a list, because picking several files at once is how a gallery
 * is actually filled. A photo may be tied to one variant, and that variant has
 * to belong to this product — the `exists` rule is scoped to it rather than to
 * the table, so a variant id from another product is a validation error and
 * not a cross-product write.
 */
class StoreProductImageRequest extends FormRequest
{
    /** How many photos one request may carry. */
    public const MAX_FILES = 10;

    /** The largest file accepted, in kilobytes. */
    public const MAX_KILOBYTES = 4096;

    /** The smallest usable photo, so a thumbnail is never upscaled. */
    public const MIN_DIMENSION = 200;

    /**
     * The route carries `can:products.update` and the controller asks
     * `ProductPolicy::update`, which additionally demands a central actor
     * (BR-1).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'images.*' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.self::MAX_KILOBYTES,
                'dimensions:min_width='.self::MIN_DIMENSION.',min_height='.self::MIN_DIMENSION,
            ],
            'product_variant_id' => [
                'nullable', 'integer',
                Rule::exists('product_variants', 'id')->where('product_id', $this->product()->getKey()),
            ],
        ];
    }

    /**
     * The uploaded files, as `StoreProductImages` wants them.
     *
     * @return array<int, UploadedFile>
     */
    public function uploadedImages(): array
    {
        /** @var array<int, UploadedFile> $files */
        $files = $this->file('images', []);

        return array_values($files);
    }

    public function variantId(): ?int
    {
        $variantId = $this->input('product_variant_id');

        return $variantId === null || $variantId === '' ? null : (int) $variantId;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'images' => 'foto',
            'images.*' => 'foto',
            'product_variant_id' => 'varian',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'images.required' => 'Pilih minimal satu foto untuk diunggah.',
            'images.max' => 'Maksimal '.self::MAX_FILES.' foto per unggahan.',
            'images.*.image' => 'Berkas :position bukan gambar.',
            'images.*.mimes' => 'Foto harus berformat JPG, PNG, atau WEBP.',
            'images.*.max' => 'Ukuran foto maksimal '.(self::MAX_KILOBYTES / 1024).' MB.',
            'images.*.dimensions' => 'Foto minimal '.self::MIN_DIMENSION.'×'.self::MIN_DIMENSION.' piksel agar tidak pecah saat ditampilkan.',
            'product_variant_id.exists' => 'Varian tersebut bukan milik produk ini.',
        ];
    }

    private function product(): Product
    {
        /** @var Product $product */
        $product = $this->route('product');

        return $product;
    }
}
