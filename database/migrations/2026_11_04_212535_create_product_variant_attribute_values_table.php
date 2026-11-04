<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which attribute values make up each variant, e.g. Merah + XL.
     *
     * Constraint names are given explicitly: the ones Laravel derives from this
     * table's name run past MySQL's 64-character limit for identifiers.
     *
     * Two application rules sit on top of the unique index: a variant may not
     * hold two values of the same attribute, and the combination has to be
     * unique within its product. Neither can be expressed as a constraint on
     * this table, so both are validated in the variant editor (P1-E3-T6).
     */
    public function up(): void
    {
        Schema::create('product_variant_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')
                ->constrained(indexName: 'variant_attr_values_variant_id_foreign')
                ->cascadeOnDelete();
            $table->foreignId('product_attribute_value_id')
                ->constrained(indexName: 'variant_attr_values_value_id_foreign')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['product_variant_id', 'product_attribute_value_id'],
                'variant_attr_values_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_attribute_values');
    }
};
