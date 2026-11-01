<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Terms the admin has set up. Exactly one is current; students can only register
     * with a COR for that term.
     */
    public function up(): void
    {
        Schema::create('academic_terms', function (Blueprint $table) {
            $table->id();
            $table->string('academic_year', 9); // e.g. '2026-2027'
            $table->enum('semester', ['1st', '2nd', 'summer']); // App\Enums\Semester
            $table->boolean('is_current')->default(false)->index();
            $table->timestamp('set_current_at')->nullable();
            $table->timestamps();

            $table->unique(['academic_year', 'semester']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_terms');
    }
};
