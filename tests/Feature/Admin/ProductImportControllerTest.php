<?php

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\ProductCsvSchema;
use Illuminate\Http\UploadedFile;
use Inertia\Support\SessionKey;

/*
 * CSV import and export of the catalogue (P1-E3-T11).
 *
 * The rule that shapes most of these: an import is all-or-nothing. A file with
 * one bad row changes nothing, because a half-loaded catalogue is worse than a
 * rejected file — nobody could tell which half got in.
 */

/**
 * Builds a CSV upload from rows keyed by column name.
 *
 * The header decides the order and which columns appear, so a test can drop a
 * column simply by leaving it out of `$header`.
 *
 * @param  array<int, array<string, string>>  $rows
 * @param  array<int, string>|null  $header
 */
function csvUpload(array $rows, ?array $header = null): UploadedFile
{
    $header ??= ProductCsvSchema::COLUMNS;

    $escape = fn (string $cell): string => str_contains($cell, ',') ? '"'.$cell.'"' : $cell;

    $lines = [implode(',', $header)];

    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(
            fn (string $column): string => $escape((string) ($row[$column] ?? '')),
            $header,
        ));
    }

    return UploadedFile::fake()->createWithContent('produk.csv', implode("\n", $lines)."\n");
}

/**
 * The import report the controller flashed, as `ProductImportResult::toArray()`
 * built it.
 *
 * Read from the session rather than asserted with `assertInertiaFlash`, which
 * compares the whole value: these tests are about single fields of a report
 * that also carries counts and a summary sentence.
 *
 * @return array<string, mixed>
 */
function importReport(): array
{
    /** @var array<string, mixed> $flash */
    $flash = session(SessionKey::FLASH_DATA, []);

    /** @var array<string, mixed> $report */
    $report = $flash['import'] ?? [];

    return $report;
}

/**
 * One well-formed row keyed by column, ready to be overridden per test.
 *
 * @return array<string, string>
 */
function csvRow(Category $category, array $overrides = []): array
{
    return [
        'sku' => 'KPS-16-PUTIH',
        'barcode' => '8991102000123',
        'name' => 'Kipas Angin Berdiri 16 Inci',
        'slug' => '',
        'category_slug' => $category->slug,
        'type' => ProductType::Simple->value,
        'base_price' => '250000',
        'compare_at_price' => '300000',
        'cost_price' => '180000',
        'weight_gram' => '3500',
        'length_cm' => '40',
        'width_cm' => '40',
        'height_cm' => '120',
        'short_description' => 'Kipas hemat listrik.',
        'is_active' => '1',
        'is_featured' => '0',
        'published_at' => '2026-11-01 08:00:00',
        'meta_title' => '',
        'meta_description' => '',
        ...$overrides,
    ];
}

it('redirects guests to the login page', function () {
    $this->get(route('admin.products.import.show'))->assertRedirect(route('login'));
});

it('shows the import screen to central staff with the csv schema', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.products.import.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/products/import')
            ->has('schema.columns')
            ->has('schema.required_columns'));
});

it('refuses the import screen to a branch admin', function () {
    $this->actingAs(User::factory()->adminCabang()->create())
        ->get(route('admin.products.import.show'))
        ->assertForbidden();
});

it('creates a product from one row', function () {
    $category = Category::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.import.store'), [
            'file' => csvUpload([csvRow($category)]),
        ])
        ->assertRedirect();

    $product = Product::query()->sole();

    expect($product->name)->toBe('Kipas Angin Berdiri 16 Inci')
        ->and($product->slug)->toBe('kipas-angin-berdiri-16-inci')
        ->and($product->base_price)->toBe(250_000)
        ->and($product->category_id)->toBe($category->id)
        ->and($product->variants()->where('is_default', true)->value('sku'))->toBe('KPS-16-PUTIH');
});

it('gives an imported product its default variant, like the panel does', function () {
    $category = Category::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.import.store'), ['file' => csvUpload([csvRow($category)])]);

    expect(Product::query()->sole()->variants()->where('is_default', true)->count())->toBe(1);
});

it('updates the product a known sku already belongs to', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->for($category)->create(['name' => 'Nama Lama', 'base_price' => 100_000]);
    ProductVariant::factory()->for($product)->default()->create(['sku' => 'KPS-16-PUTIH']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.import.store'), ['file' => csvUpload([csvRow($category)])]);

    expect(Product::count())->toBe(1)
        ->and($product->fresh()->name)->toBe('Kipas Angin Berdiri 16 Inci')
        ->and($product->fresh()->base_price)->toBe(250_000);
});

it('is safe to run twice over the same file', function () {
    $category = Category::factory()->create();
    $file = fn () => csvUpload([csvRow($category)]);
    $actor = User::factory()->adminPusat()->create();

    $this->actingAs($actor)->post(route('admin.products.import.store'), ['file' => $file()]);
    $this->actingAs($actor)->post(route('admin.products.import.store'), ['file' => $file()]);

    expect(Product::count())->toBe(1)
        ->and(ProductVariant::count())->toBe(1);
});

it('reports how many products were created and updated', function () {
    $category = Category::factory()->create();
    $existing = Product::factory()->for($category)->create();
    ProductVariant::factory()->for($existing)->default()->create(['sku' => 'LAMA-01']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.import.store'), [
            'file' => csvUpload([
                csvRow($category, ['sku' => 'LAMA-01', 'barcode' => '']),
                csvRow($category, ['sku' => 'BARU-01', 'barcode' => '', 'name' => 'Produk Baru']),
            ]),
        ])
        ->assertRedirect();

    expect(importReport())
        ->toMatchArray(['was_applied' => true, 'created' => 1, 'updated' => 1]);
});

it('imports nothing at all when one row is invalid', function () {
    $category = Category::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.import.store'), [
            'file' => csvUpload([
                csvRow($category, ['sku' => 'BAIK-01', 'barcode' => '']),
                csvRow($category, ['sku' => 'RUSAK-01', 'barcode' => '', 'base_price' => 'dua ratus ribu']),
            ]),
        ])
        ->assertRedirect();

    expect(Product::count())->toBe(0);
});

it('reports the line number and the reason for every bad row', function () {
    $category = Category::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.import.store'), [
            'file' => csvUpload([
                csvRow($category, ['sku' => 'RUSAK-01', 'barcode' => '', 'name' => '']),
                csvRow($category, ['sku' => 'RUSAK-02', 'barcode' => '', 'category_slug' => 'tidak-ada']),
            ]),
        ])
        ->assertRedirect();

    $report = importReport();

    expect($report['was_applied'])->toBeFalse()
        ->and($report['errors'])->toHaveCount(2)
        // The header is line 1, so the data rows are 2 and 3.
        ->and($report['errors'][0]['line'])->toBe(2)
        ->and($report['errors'][1]['line'])->toBe(3)
        ->and($report['errors'][1]['messages'])->not->toBeEmpty();
});

it('refuses a duplicate sku inside the same file', function () {
    $category = Category::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.import.store'), [
            'file' => csvUpload([
                csvRow($category, ['sku' => 'SAMA-01', 'barcode' => '']),
                csvRow($category, ['sku' => 'SAMA-01', 'barcode' => '', 'name' => 'Nama Lain']),
            ]),
        ]);

    expect(Product::count())->toBe(0);
});

it('refuses a file missing a required column', function () {
    $category = Category::factory()->create();
    $header = array_values(array_diff(ProductCsvSchema::COLUMNS, ['base_price']));
    $row = csvRow($category);
    unset($row['base_price']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.import.store'), [
            'file' => csvUpload([$row], $header),
        ])
        ->assertRedirect();

    expect(importReport()['was_applied'])->toBeFalse()
        ->and(importReport()['errors'])->not->toBeEmpty()
        ->and(Product::count())->toBe(0);
});

it('refuses a file with only a header', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.import.store'), ['file' => csvUpload([])])
        ->assertRedirect();

    expect(importReport()['was_applied'])->toBeFalse();
});

it('refuses a strikethrough price that is not higher, exactly as the form does', function () {
    $category = Category::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.import.store'), [
            'file' => csvUpload([
                csvRow($category, ['barcode' => '', 'base_price' => '250000', 'compare_at_price' => '250000']),
            ]),
        ]);

    expect(Product::count())->toBe(0);
});

it('refuses a sku outside the allowed alphabet', function () {
    $category = Category::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.import.store'), [
            'file' => csvUpload([csvRow($category, ['sku' => 'kps 16 putih', 'barcode' => ''])]),
        ]);

    expect(Product::count())->toBe(0);
});

it('reads a spreadsheet idea of a boolean', function (string $raw, bool $expected) {
    $category = Category::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.import.store'), [
            'file' => csvUpload([csvRow($category, ['barcode' => '', 'is_featured' => $raw])]),
        ]);

    expect(Product::query()->sole()->is_featured)->toBe($expected);
})->with([
    'one' => ['1', true],
    'TRUE' => ['TRUE', true],
    'ya' => ['ya', true],
    'zero' => ['0', false],
    'tidak' => ['tidak', false],
    'blank' => ['', false],
]);

it('treats a blank optional cell as not set rather than as an empty string', function () {
    $category = Category::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.import.store'), [
            'file' => csvUpload([
                csvRow($category, [
                    'barcode' => '',
                    'compare_at_price' => '',
                    'cost_price' => '',
                    'length_cm' => '',
                    'published_at' => '',
                    'meta_title' => '',
                ]),
            ]),
        ]);

    $product = Product::query()->sole();

    expect($product->compare_at_price)->toBeNull()
        ->and($product->cost_price)->toBeNull()
        ->and($product->length_cm)->toBeNull()
        ->and($product->published_at)->toBeNull()
        ->and($product->meta_title)->toBeNull()
        ->and($product->variants()->value('barcode'))->toBeNull();
});

it('refuses an import from a branch admin', function () {
    $category = Category::factory()->create();

    $this->actingAs(User::factory()->adminCabang()->create())
        ->post(route('admin.products.import.store'), ['file' => csvUpload([csvRow($category)])])
        ->assertForbidden();

    expect(Product::count())->toBe(0);
});

it('refuses a file that is not a csv', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.import.store'), [
            'file' => UploadedFile::fake()->create('katalog.pdf', 10, 'application/pdf'),
        ])
        ->assertSessionHasErrors('file');
});

it('exports the catalogue as csv with the schema header', function () {
    $product = Product::factory()->withDefaultVariant()->create(['name' => 'Kipas Angin Berdiri']);

    $response = $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.products.export'));

    $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    expect($csv)->toContain(implode(',', ProductCsvSchema::COLUMNS))
        ->and($csv)->toContain('Kipas Angin Berdiri')
        ->and($csv)->toContain($product->variants()->value('sku'));
});

it('exports only what the filters on screen were showing', function () {
    Product::factory()->withDefaultVariant()->create(['name' => 'Kipas Angin Berdiri']);
    Product::factory()->withDefaultVariant()->create(['name' => 'Setrika Uap Listrik']);

    $csv = $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.products.export', ['search' => 'Setrika']))
        ->streamedContent();

    expect($csv)->toContain('Setrika Uap Listrik')
        ->and($csv)->not->toContain('Kipas Angin Berdiri');
});

it('lets every role holding products.view export', function () {
    Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminCabang()->create())
        ->get(route('admin.products.export'))
        ->assertOk();
});

it('forbids an export to a customer', function () {
    $this->actingAs(User::factory()->customer()->create())
        ->get(route('admin.products.export'))
        ->assertForbidden();
});

it('offers an empty template with one example row', function () {
    $csv = $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.products.import.template'))
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain(implode(',', ProductCsvSchema::COLUMNS))
        ->and($csv)->toContain('KPS-16-PUTIH');
});

it('round-trips an export back through the import unchanged', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->for($category)->withDefaultVariant()->create([
        'name' => 'Rice Cooker Digital',
        'base_price' => 625_000,
        'weight_gram' => 4_100,
    ]);

    $actor = User::factory()->adminPusat()->create();
    $csv = $this->actingAs($actor)->get(route('admin.products.export'))->streamedContent();

    $this->actingAs($actor)->post(route('admin.products.import.store'), [
        'file' => UploadedFile::fake()->createWithContent('reimport.csv', $csv),
    ]);

    expect(Product::count())->toBe(1)
        ->and($product->fresh()->name)->toBe('Rice Cooker Digital')
        ->and($product->fresh()->base_price)->toBe(625_000)
        ->and($product->fresh()->weight_gram)->toBe(4_100)
        ->and($product->fresh()->slug)->toBe($product->slug);
});
