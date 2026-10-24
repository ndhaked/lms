<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_halls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->time('opening_time')->nullable();
            $table->time('closing_time')->nullable();
            $table->string('status', 20)->default('active'); // active, inactive
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['library_id', 'name']);
        });

        Schema::create('seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('study_hall_id')->constrained('study_halls')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('type', 30)->default('standard'); // standard, cabin, computer, group
            $table->string('status', 20)->default('available'); // available, occupied, reserved, unavailable, maintenance
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['library_id', 'code']);
            $table->index(['library_id', 'study_hall_id', 'status']);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // optional student login
            $table->string('student_code', 30);
            $table->string('name');
            $table->string('phone', 30);
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            $table->date('registered_on');
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active'); // active, suspended, inactive
            $table->timestamps();

            $table->unique(['library_id', 'student_code']);
            $table->index(['library_id', 'phone']);
            $table->index(['library_id', 'name']);
        });

        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('duration_unit', 20); // daily, weekly, monthly, quarterly, half_yearly, yearly
            $table->decimal('price', 10, 2);
            $table->decimal('registration_fee', 10, 2)->default(0);
            $table->string('seat_type', 20)->default('flexible'); // fixed, flexible
            $table->time('shift_start')->nullable();
            $table->time('shift_end')->nullable();
            $table->unsignedSmallInteger('grace_days')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['library_id', 'name']);
        });

        Schema::create('student_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_plan_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('price', 10, 2);          // snapshot of the plan at purchase
            $table->decimal('registration_fee', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->string('status', 20)->default('active'); // active, suspended, cancelled (expiry is derived from ends_on)
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['library_id', 'student_id']);
            $table->index(['library_id', 'ends_on']);
        });

        Schema::create('seat_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_membership_id')->nullable()->constrained('student_memberships')->nullOnDelete();
            $table->timestamp('allocated_at');
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason', 40)->nullable(); // manual, membership_ended, reassigned
            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            // Set while the allocation is active, NULL after release. The unique keys below make
            // "one active allocation per seat / per student" a database guarantee, not just app logic.
            $table->unsignedBigInteger('active_seat_id')->nullable();
            $table->unsignedBigInteger('active_student_id')->nullable();
            $table->timestamps();

            $table->unique('active_seat_id');
            $table->unique('active_student_id');
            $table->index(['library_id', 'seat_id']);
            $table->index(['library_id', 'student_id']);
        });
    }

    public function down(): void
    {
        foreach (['seat_allocations', 'student_memberships', 'membership_plans', 'students', 'seats', 'study_halls'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
