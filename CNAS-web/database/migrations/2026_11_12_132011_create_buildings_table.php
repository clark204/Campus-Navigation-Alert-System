<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shapes on the campus map: the campus outline, buildings, facilities and the evacuation area. `code` is the id
     * used everywhere else (alerts.building_codes, the map API), e.g. 'b1'. `outline` is the GeoJSON polygon
     * coordinates. `floors` is null for the campus outline and the evacuation area.
     */
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('type', 20);
            $table->unsignedTinyInteger('floors')->nullable();
            $table->string('landmark_name')->nullable();
            $table->json('outline');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('buildings');
    }
};
