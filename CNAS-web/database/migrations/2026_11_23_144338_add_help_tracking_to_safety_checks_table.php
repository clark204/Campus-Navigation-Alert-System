<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "I need help" follow-up (docs/alerts.md): where the person is and who is helping. Only the latest position is
     * kept (no trail), updated about every 15 s while their help request is open and their page or app is open. An
     * admin taps "I'm responding" (responder_id, responding_at) and then "Mark helped" (helped_by, helped_at).
     * Admin-only, deleted with the answer 30 days after the alert ends (SafetyCheck::prunable).
     */
    public function up(): void
    {
        Schema::table('safety_checks', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('note');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedSmallInteger('accuracy_m')->nullable()->after('longitude');
            $table->timestamp('located_at')->nullable()->after('accuracy_m');
            $table->foreignId('responder_id')->nullable()->after('located_at')->constrained('users')->nullOnDelete();
            $table->timestamp('responding_at')->nullable()->after('responder_id');
            $table->foreignId('helped_by')->nullable()->after('responding_at')->constrained('users')->nullOnDelete();
            $table->timestamp('helped_at')->nullable()->after('helped_by');
        });
    }

    public function down(): void
    {
        Schema::table('safety_checks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responder_id');
            $table->dropConstrainedForeignId('helped_by');
            $table->dropColumn(['latitude', 'longitude', 'accuracy_m', 'located_at', 'responding_at', 'helped_at']);
        });
    }
};
