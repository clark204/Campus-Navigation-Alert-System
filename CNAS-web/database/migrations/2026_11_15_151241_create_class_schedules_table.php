<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A student's class meetings, one row per meeting (a subject that meets twice a week has two rows).
     * `room_text` is the room exactly as written ("CompLab1", "Online", "TBA"): no room id is stored, the room is
     * matched when the schedule is read (App\Services\RoomMatcher). `source` is App\Enums\ScheduleSource.
     */
    public function up(): void
    {
        Schema::create('class_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_term_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 50); // e.g. 'IT 119'
            $table->string('section', 30)->nullable();
            $table->string('day', 3); // App\Enums\Weekday: mon..sun
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('room_text', 100);
            $table->string('source', 10); // 'cor' or 'manual'
            $table->timestamps();

            // Every read is "this student's rows for the current term".
            $table->index(['user_id', 'academic_term_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_schedules');
    }
};
