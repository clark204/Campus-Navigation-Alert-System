<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Users belong to a program, and their college comes through it. A user takes the program of their college when
     * that college has exactly one program. A college with several (e.g. Teacher Education: BEED and BSED) can't say
     * which one the user is in, so the user's program is left null for an admin to set.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('program_code', 20)->nullable();
            $table->foreign('program_code')->references('code')->on('programs')
                ->cascadeOnUpdate()->nullOnDelete();
        });

        $singleProgramByCollege = DB::table('programs')
            ->select('college_id', DB::raw('MIN(code) as code'))
            ->groupBy('college_id')
            ->havingRaw('COUNT(*) = 1')
            ->pluck('code', 'college_id');

        foreach ($singleProgramByCollege as $collegeId => $programCode) {
            DB::table('users')->where('college_id', $collegeId)->update(['program_code' => $programCode]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['college_id']);
            $table->dropColumn('college_id');
        });
    }

    /**
     * Restore users.college_id from each user's program. Users without a program have no college afterwards.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('college_id', 10)->nullable();
            $table->foreign('college_id')->references('id')->on('colleges')
                ->cascadeOnUpdate()->nullOnDelete();
        });

        DB::statement(
            'UPDATE users SET college_id = (SELECT programs.college_id FROM programs WHERE programs.code = users.program_code) '.
            'WHERE program_code IS NOT NULL'
        );

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['program_code']);
            $table->dropColumn('program_code');
        });
    }
};
