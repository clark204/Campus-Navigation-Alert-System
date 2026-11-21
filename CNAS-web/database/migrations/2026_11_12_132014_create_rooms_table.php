<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rooms people can get directions to. `code` is e.g. 'r5' (used in /navigate?to=r5); latitude and longitude are
     * the door; `door` is an optional label for it; `is_it` marks IT college rooms.
     */
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('floor');
            $table->string('type', 20);
            $table->boolean('is_it')->default(false);
            $table->string('door')->nullable();
            $table->decimal('latitude', 11, 8);
            $table->decimal('longitude', 11, 8);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
