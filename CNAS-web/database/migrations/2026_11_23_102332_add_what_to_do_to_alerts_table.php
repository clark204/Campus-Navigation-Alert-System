<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "What to do now": one short sentence (max 100 characters) that the takeover, banner and push show in bold.
     * Required for new emergencies; older alerts have none and fall back to the first line of their instructions
     * (Alert::whatToDo()).
     */
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->string('what_to_do', 100)->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropColumn('what_to_do');
        });
    }
};
