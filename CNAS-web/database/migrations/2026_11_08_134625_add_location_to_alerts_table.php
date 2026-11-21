<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the incident is: a pin with a danger radius and the affected buildings (ids from
     * public/maps/buildings.geojson). All null for whole-campus alerts such as a typhoon.
     * resolution says how the alert ended: all clear or false alarm.
     */
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('is_drill');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedSmallInteger('danger_radius_m')->nullable()->after('longitude');
            $table->json('building_codes')->nullable()->after('danger_radius_m');
            $table->string('resolution', 15)->nullable()->after('resolved_by');
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'danger_radius_m', 'building_codes', 'resolution']);
        });
    }
};
