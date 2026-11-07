<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Category names that read like a real catalogue, since Faker has no
     * id_ID product taxonomy.
     *
     * @var array<int, string>
     */
    public const NAMES = [
        'Elektronik', 'Fesyen', 'Kecantikan', 'Kesehatan', 'Rumah Tangga',
        'Olahraga', 'Otomotif', 'Makanan & Minuman', 'Buku & Alat Tulis',
        'Perawatan Bayi', 'Hobi & Koleksi', 'Perkakas',
    ];

    /**
     * The slug follows the *final* `name` attribute, not the random name built
     * here — see the note in `ProductFactory::definition()` (BUG-08).
     * `CategoryController` searches name **or** slug too.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'name' => fake()->randomElement(self::NAMES).' '.fake()->unique()->numberBetween(1, 99999),
            'slug' => fn (array $attributes): string => Str::slug((string) $attributes['name'])
                .'-'.fake()->unique()->numberBetween(1, 99999),
            'description' => fake()->sentence(),
            'image_path' => null,
            'icon' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * A child of the given category, or of a freshly made root.
     */
    public function childOf(?Category $parent = null): static
    {
        return $this->state(fn (): array => [
            'parent_id' => $parent?->getKey() ?? Category::factory(),
        ]);
    }

    public function sortedAt(int $position): static
    {
        return $this->state(['sort_order' => $position]);
    }
}
