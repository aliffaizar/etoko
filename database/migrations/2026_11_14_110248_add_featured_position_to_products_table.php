<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The order of the featured products on the homepage (P2-E8-T4).
     *
     * `is_featured` already says *which* products are featured; this says in
     * what order. NULL means "featured, not yet placed", which sorts after every
     * placed product and falls back to the newest first, so flagging a product
     * never leaves it out of the rail.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('featured_position')->nullable()->after('is_featured');
            $table->index(['is_featured', 'featured_position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_featured', 'featured_position']);
            $table->dropColumn('featured_position');
        });
    }
};
