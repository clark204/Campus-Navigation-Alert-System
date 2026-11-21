<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The floor a path is on: 1 for the ground walkways and gates (every path so far), 2 and up for corridors inside
     * buildings. Floors only connect through stairs.
     */
    public function up(): void
    {
        Schema::table('paths', function (Blueprint $table) {
            $table->unsignedTinyInteger('floor')->default(1)->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('paths', function (Blueprint $table) {
            $table->dropColumn('floor');
        });
    }
};
