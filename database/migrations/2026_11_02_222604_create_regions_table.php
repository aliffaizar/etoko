<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('regions')->cascadeOnDelete();
            $table->string('type', 20)->comment('province, city, district or village');
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->timestamps();

            $table->index(['type', 'name']);
            $table->index(['parent_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
