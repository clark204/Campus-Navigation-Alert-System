<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A short-lived record between "COR checked" and "account created".
     * Only the SHA-256 of the draft token is stored; the raw token goes to the client once.
     * The COR PDF itself is never kept.
     */
    public function up(): void
    {
        Schema::create('registration_drafts', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->json('data');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registration_drafts');
    }
};
