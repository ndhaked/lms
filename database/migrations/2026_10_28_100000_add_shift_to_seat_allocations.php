<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A seat can be shared by students who study in different shifts (morning, afternoon, evening, night), or held
 * for the whole day. "One active allocation per seat" therefore becomes "one active allocation per seat and shift";
 * the cross-shift rule (a full-day booking excludes the others) is enforced under the seat row lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_allocations', function (Blueprint $table) {
            $table->string('shift', 12)->default('fullday')->after('student_membership_id');
            $table->string('active_slot', 40)->nullable()->after('active_student_id');
        });

        DB::table('seat_allocations')->whereNull('released_at')->update(['active_slot' => DB::raw("CONCAT(seat_id, '-fullday')")]);

        Schema::table('seat_allocations', function (Blueprint $table) {
            $table->dropUnique(['active_seat_id']);
            $table->unique('active_slot');
        });
    }

    public function down(): void
    {
        Schema::table('seat_allocations', function (Blueprint $table) {
            $table->dropUnique(['active_slot']);
            $table->unique('active_seat_id');
            $table->dropColumn(['shift', 'active_slot']);
        });
    }
};
