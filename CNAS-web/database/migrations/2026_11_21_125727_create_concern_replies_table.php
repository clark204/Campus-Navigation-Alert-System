<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The admin's replies to a concern (Admin > Concerns). Each one is emailed to the concern's email and, when the
     * sender has an account, shows in their portal (Help & contact, and the notification bell). `user_id` is the admin
     * who wrote it (null once that account is gone).
     */
    public function up(): void
    {
        Schema::create('concern_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('concern_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('message');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concern_replies');
    }
};
