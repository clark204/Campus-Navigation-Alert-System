<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sign-in history for the admin dashboard: one row per successful sign-in, kept 90 days
     * (App\Models\LoginActivity::prunable). Never holds passwords or tokens.
     */
    public function up(): void
    {
        Schema::create('login_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 10); // App\Enums\LoginChannel
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device', 100)->nullable(); // e.g. "Chrome on Windows"
            // Links a mobile app sign-in to its token so the API logout can close the right row. The id only, never the token.
            $table->unsignedBigInteger('personal_access_token_id')->nullable()->index();
            $table->timestamp('logged_in_at')->index();
            $table->timestamp('logged_out_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_activities');
    }
};
