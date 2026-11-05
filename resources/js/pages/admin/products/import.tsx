import { Form, Head, Link, usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Download, Upload } from 'lucide-react';
import ProductImportController from '@/actions/App/Http/Controllers/Admin/ProductImportController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as productsIndex } from '@/routes/admin/products';
import productImport from '@/routes/admin/products/import';
import type { ProductImportReport } from '@/types';

type ProductImportProps = {
    schema: {
        columns: string[];
        required_columns: string[];
        max_rows: number;
        max_kilobytes: number;
    };
};

type ImportFormData = {
    file: File | null;
};

/**
 * Loading the catalogue from a spreadsheet (P1-E3-T11).
 *
 * The screen is built around the loop people actually run: download the
 * template, fill it in, import, read what was wrong, fix, import again. That
 * is why the rejected rows are listed with their line numbers rather than
 * summarised — "3 baris bermasalah" is not something anyone can act on.
 *
 * An import is all-or-nothing, so a rejected file leaves the catalogue
 * untouched and the same file can be corrected and sent again.
 */
export default function ProductImport({ schema }: ProductImportProps) {
    const report = usePage().props.flash?.import;

    return (
        <>
            <Head title="Impor Produk" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Impor & Ekspor Produk"
                        description="Cara tercepat mengisi katalog pertama kali: unduh template, isi di spreadsheet, lalu impor kembali."
                    />
                    <Button variant="outline" asChild>
                        <Link href={productsIndex()}>Kembali ke daftar</Link>
                    </Button>
                </div>

                {report && <ImportReportCard report={report} />}

                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Unggah berkas CSV</CardTitle>
                        <CardDescription>
                            Maksimal {schema.max_rows} baris dan{' '}
                            {schema.max_kilobytes / 1024} MB per berkas. Bila
                            ada satu baris yang salah,{' '}
                            <strong>tidak ada</strong> produk yang diubah —
                            perbaiki barisnya lalu impor berkas yang sama lagi.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <Form<ImportFormData>
                            {...ProductImportController.store.form()}
                            className="flex flex-col gap-4 sm:flex-row sm:items-end"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid min-w-0 flex-1 gap-2">
                                        <Label htmlFor="import_file">
                                            Berkas CSV
                                        </Label>
                                        <Input
                                            id="import_file"
                                            name="file"
                                            type="file"
                                            required
                                            accept=".csv,text/csv"
                                            className="file:me-3 file:text-foreground"
                                        />
                                        <InputError message={errors.file} />
                                    </div>
                                    <Button type="submit" disabled={processing}>
                                        <Upload aria-hidden />
                                        Impor
                                    </Button>
                                </>
                            )}
                        </Form>

                        <div className="flex flex-wrap gap-2 border-t pt-4">
                            <Button variant="secondary" asChild>
                                <a href={productImport.template.url()}>
                                    <Download aria-hidden />
                                    Unduh Template
                                </a>
                            </Button>
                            <Button variant="outline" asChild>
                                <a href={ProductImportController.export.url()}>
                                    <Download aria-hidden />
                                    Ekspor Semua Produk
                                </a>
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Kolom yang dikenali</CardTitle>
                        <CardDescription>
                            <strong>SKU</strong> adalah kuncinya: baris dengan
                            SKU yang sudah ada memperbarui produknya, SKU baru
                            membuat produk baru. Kategori ditunjuk dengan{' '}
                            <span className="font-mono">slug</span>-nya dan
                            harus sudah ada. Deskripsi panjang (HTML) tidak ikut
                            CSV dan tetap diisi di layar produk.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul className="flex flex-wrap gap-2">
                            {schema.columns.map((column) => {
                                const isRequired =
                                    schema.required_columns.includes(column);

                                return (
                                    <li key={column}>
                                        <span
                                            className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-1 font-mono text-xs ${
                                                isRequired
                                                    ? 'border-primary/40 bg-primary/5'
                                                    : 'bg-background'
                                            }`}
                                        >
                                            {column}
                                            {isRequired && (
                                                <span
                                                    className="font-sans text-[0.65rem] text-muted-foreground"
                                                    title="Kolom wajib"
                                                >
                                                    wajib
                                                </span>
                                            )}
                                        </span>
                                    </li>
                                );
                            })}
                        </ul>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

function ImportReportCard({ report }: { report: ProductImportReport }) {
    if (report.was_applied) {
        return (
            <Alert className="max-w-3xl">
                <CheckCircle2 aria-hidden />
                <AlertTitle>Impor selesai</AlertTitle>
                <AlertDescription>
                    {report.created} produk baru dibuat dan {report.updated}{' '}
                    produk diperbarui.
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <Card className="max-w-3xl border-destructive/40">
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <AlertTriangle
                        aria-hidden
                        className="size-4 text-destructive"
                    />
                    Impor dibatalkan
                </CardTitle>
                <CardDescription>
                    {report.errors.length} baris bermasalah, jadi tidak ada
                    produk yang diubah. Perbaiki baris di bawah lalu impor
                    berkas yang sama lagi.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ul className="flex flex-col divide-y rounded-lg border">
                    {report.errors.map((error) => (
                        <li
                            key={error.line}
                            className="flex flex-col gap-1 p-3 sm:flex-row sm:gap-4"
                        >
                            <p className="shrink-0 text-sm font-medium tabular-nums sm:w-24">
                                Baris {error.line}
                            </p>
                            <ul className="min-w-0 list-inside list-disc text-sm text-muted-foreground">
                                {error.messages.map((message) => (
                                    <li key={message}>{message}</li>
                                ))}
                            </ul>
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}

ProductImport.layout = {
    breadcrumbs: [
        { title: 'Produk', href: productsIndex() },
        { title: 'Impor & Ekspor', href: productImport.show() },
    ],
};
