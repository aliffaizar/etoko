<?php

use App\Enums\ProductType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            // Restricted rather than cascaded: losing a category must never
            // take its products with it. `Category::deletionBlockedReason()`
            // refuses the soft delete too, so the panel never gets this far.
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type', 20)->default(ProductType::Simple->value);
            $table->string('short_description', 500)->nullable();
            $table->longText('description')->nullable();

            // Rupiah as whole integers (ADR-008, BR-10).
            $table->unsignedBigInteger('base_price');
            $table->unsignedBigInteger('compare_at_price')->nullable();
            $table->unsignedBigInteger('cost_price')->nullable();

            $table->unsignedInteger('weight_gram')->default(0);
            $table->unsignedInteger('length_cm')->nullable();
            $table->unsignedInteger('width_cm')->nullable();
            $table->unsignedInteger('height_cm')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();

            // Denormalised from reviews and orders, written by their own
            // observers in later epics (P2-E7, P1-E7).
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('sold_count')->default(0);
            $table->unsignedInteger('view_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // The storefront's catalogue query, and the panel's listing.
            $table->index(['is_active', 'published_at']);
            $table->index(['category_id', 'is_active']);
            $table->index('is_featured');
        });

        $this->addSearchIndex();
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }

    /**
     * Catalogue search over the product's own text.
     *
     * A fulltext index is MySQL-only, and the test suite runs on SQLite
     * in-memory for speed (docs/02-architecture.md). The index is an
     * optimisation for `MATCH ... AGAINST`; a `LIKE` search works without it,
     * so skipping it on other drivers changes performance, not behaviour.
     */
    private function addSearchIndex(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->fullText(['name', 'short_description']);
        });
    }
};
