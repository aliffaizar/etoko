<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A promotional slide on the storefront (P2-E8-T1).
     *
     * - `branch_id` NULL shows the banner at every branch; a branch id shows it
     *   only while that branch is the one the visitor is shopping from.
     * - `starts_at`/`ends_at` are optional; no bounds means "until switched off".
     * - `image_path` is a path on the `public` disk, like `categories.image_path`,
     *   so the URL is never stored.
     */
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->string('subtitle', 255)->nullable();
            $table->string('image_path')->nullable();
            $table->string('mobile_image_path')->nullable();
            $table->string('link_url', 500)->nullable();
            $table->string('position', 20)->default('hero');
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'position', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
