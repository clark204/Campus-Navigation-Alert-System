<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staircases inside a building. `code` is e.g. 's0'; `floors` is the highest floor the stair reaches; the centre
     * is where directions send people; `outline` is the GeoJSON polygon coordinates.
     */
    public function up(): void
    {
        Schema::create('stairs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('floors');
            $table->decimal('center_latitude', 11, 8);
            $table->decimal('center_longitude', 11, 8);
            $table->json('outline');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stairs');
    }
};
