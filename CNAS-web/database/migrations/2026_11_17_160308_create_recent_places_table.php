<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rooms a user last started directions to (User::recentRooms). One row per room with the latest `used_at`; only
     * the newest few are kept (RecentPlaces::KEEP), and the user can clear them.
     */
    public function up(): void
    {
        Schema::create('recent_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->timestamp('used_at');

            $table->unique(['user_id', 'room_id']);
            $table->index(['user_id', 'used_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recent_places');
    }
};
