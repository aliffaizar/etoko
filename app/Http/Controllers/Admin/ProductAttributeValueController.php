<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductAttributeValueRequest;
use App\Http\Requests\Admin\UpdateProductAttributeValueRequest;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The options of one attribute, such as Merah and Biru under Warna
 * (P1-E3-T6).
 *
 * Values have no screen of their own: they are managed on the attribute that
 * owns them, so the routes are nested and authorisation asks
 * `ProductAttributePolicy` about the parent. Scoped route bindings make a
 * value of another attribute a 404 rather than a silent cross-attribute edit.
 */
class ProductAttributeValueController extends Controller
{
    public function store(StoreProductAttributeValueRequest $request, ProductAttribute $attribute): RedirectResponse
    {
        $this->authorize('update', $attribute);

        $value = $attribute->values()->create($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Nilai {$value->displayLabel()} ditambahkan ke atribut {$attribute->name}.",
        ]);

        return back();
    }

    public function update(
        UpdateProductAttributeValueRequest $request,
        ProductAttribute $attribute,
        ProductAttributeValue $value,
    ): RedirectResponse {
        $this->authorize('update', $attribute);

        $value->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Perubahan nilai atribut berhasil disimpan.']);

        return back();
    }

    /**
     * Removes a value.
     *
     * A value that still defines a variant stays: deleting it would cascade
     * through the pivot and leave that variant without the option that tells
     * it apart from its siblings.
     */
    public function destroy(ProductAttribute $attribute, ProductAttributeValue $value): RedirectResponse
    {
        $this->authorize('update', $attribute);

        $inUse = $value->variantCount();

        abort_if(
            $inUse > 0,
            403,
            "Nilai ini masih dipakai oleh {$inUse} varian produk. Hapus atau ubah varian tersebut lebih dahulu.",
        );

        $value->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Nilai {$value->displayLabel()} dihapus."]);

        return back();
    }
}
