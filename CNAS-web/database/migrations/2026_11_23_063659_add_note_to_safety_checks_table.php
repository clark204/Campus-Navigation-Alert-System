<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "I need help" answers carry an optional note (where the person is, what is wrong). Admin-only, deleted with the
     * answer 30 days after the alert ends (SafetyCheck::prunable).
     */
    public function up(): void
    {
        Schema::table('safety_checks', function (Blueprint $table) {
            $table->string('note', 200)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('safety_checks', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
