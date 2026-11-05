<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Product\ExportProducts;
use App\Actions\Product\ImportProducts;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportProductsRequest;
use App\Http\Requests\Admin\IndexProductRequest;
use App\Models\Product;
use App\Support\ProductCsvSchema;
use App\Support\ProductListQuery;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Loading and extracting the catalogue as CSV (P1-E3-T11).
 *
 * This is what makes the first hundred products practical: nobody fills in a
 * form a hundred times. The normal loop is download the template, fill it in a
 * spreadsheet, import, read the row-by-row complaints, fix, import again — so
 * the import is re-runnable by SKU and refuses the whole file rather than
 * loading half of it.
 *
 * Writing is central (BR-1), the same as the product screen itself: the import
 * asks `ProductPolicy::create` and the export `viewAny`, because reading the
 * catalogue out is reading.
 */
class ProductImportController extends Controller
{
    public function show(): Response
    {
        $this->authorize('create', Product::class);

        return Inertia::render('admin/products/import', [
            'schema' => [
                'columns' => ProductCsvSchema::COLUMNS,
                'required_columns' => ProductCsvSchema::REQUIRED_COLUMNS,
                'max_rows' => ProductCsvSchema::MAX_ROWS,
                'max_kilobytes' => ImportProductsRequest::MAX_KILOBYTES,
            ],
        ]);
    }

    public function store(ImportProductsRequest $request, ImportProducts $importProducts): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $file = $request->file('file');
        $result = $importProducts->handle($file->getRealPath());

        Inertia::flash('import', $result->toArray());
        Inertia::flash('toast', [
            'type' => $result->hasErrors() ? 'error' : 'success',
            'message' => $result->summary(),
        ]);

        return back();
    }

    /**
     * Streams the catalogue out, honouring the filters the listing was showing.
     *
     * The query comes from `ProductController`'s own listing rules through the
     * same form request, so "export what I am looking at" means exactly that.
     */
    public function export(IndexProductRequest $request, ExportProducts $exportProducts): StreamedResponse
    {
        $this->authorize('viewAny', Product::class);

        $filename = 'produk-'.now()->format('Ymd-His').'.csv';

        return $exportProducts->handle(ProductListQuery::from($request->filters()), $filename);
    }

    /**
     * The empty template, for a catalogue with nothing to export yet.
     */
    public function template(ExportProducts $exportProducts): StreamedResponse
    {
        $this->authorize('create', Product::class);

        return $exportProducts->template('template-produk.csv');
    }
}
