<?php

namespace App\Http\Requests\Admin;

use App\Support\ProductCsvSchema;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The CSV file being imported (P1-E3-T11).
 *
 * The contents are not validated here: a per-row report is the useful answer
 * to a bad spreadsheet, and that is `ImportProducts`' job. This only decides
 * whether the upload is a CSV at all and small enough to read.
 */
class ImportProductsRequest extends FormRequest
{
    /** The largest file accepted, in kilobytes. */
    public const MAX_KILOBYTES = 4096;

    /**
     * The route carries `can:products.create` and the controller asks
     * `ProductPolicy::create`, which additionally demands a central actor
     * (BR-1).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `txt` is accepted because that is what a browser reports for a CSV on
     * some systems, and `mimetypes` on a text file is unreliable enough that
     * the extension is the honest check.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.self::MAX_KILOBYTES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['file' => 'berkas CSV'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Pilih berkas CSV yang ingin diimpor.',
            'file.mimes' => 'Berkas harus berformat CSV.',
            'file.max' => 'Ukuran berkas maksimal '.(self::MAX_KILOBYTES / 1024).' MB, atau maksimal '
                .ProductCsvSchema::MAX_ROWS.' baris.',
        ];
    }
}
