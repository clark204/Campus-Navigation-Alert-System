<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 360° photo points on the map. `code` is e.g. 'pn3'. `north_offset` is the direction in the photo that faces
     * north (degrees clockwise from the photo's centre column); null until an admin sets it. Only visible photos are
     * shown to users, and a photo can only be made visible once north is set. The paths are on the public disk.
     */
    public function up(): void
    {
        Schema::create('panoramas', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->decimal('latitude', 11, 8);
            $table->decimal('longitude', 11, 8);
            $table->unsignedTinyInteger('floor')->default(1);
            $table->decimal('north_offset', 5, 2)->nullable();
            $table->boolean('is_visible')->default(false);
            $table->string('image_path');
            $table->string('thumb_path');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('panoramas');
    }
};
