<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('recipient_name');
            $table->string('phone', 20);
            $table->foreignId('province_id')->nullable()->nullOnDelete()->constrained('regions');
            $table->foreignId('city_id')->nullable()->nullOnDelete()->constrained('regions');
            $table->foreignId('district_id')->nullable()->nullOnDelete()->constrained('regions');
            $table->string('province_name');
            $table->string('city_name');
            $table->string('district_name');
            $table->string('postal_code', 10);
            $table->text('address_line');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
