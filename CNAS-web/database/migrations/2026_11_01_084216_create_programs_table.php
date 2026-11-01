<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Program codes as printed on the COR (e.g. 'BSIT'), each belonging to one college.
     */
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->string('code', 20)->primary();
            $table->string('name');
            $table->string('college_id', 10);
            $table->foreign('college_id')->references('id')->on('colleges')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('programs');
    }
};
