<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reservations')) {
            return;
        }

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('book_id')->constrained('books')->restrictOnDelete();
            $table->string('status', 12)->default('waiting');            // waiting | ready | fulfilled | cancelled | expired
            $table->timestamp('requested_at');
            $table->foreignId('book_copy_id')->nullable()->constrained('book_copies')->nullOnDelete();   // the copy held for them while ready
            $table->timestamp('ready_at')->nullable();
            $table->date('expires_on')->nullable();                      // last day to collect once ready
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // A member can wait for a title only once at a time, enforced by the database.
            $table->string('active_pair', 40)->nullable()->virtualAs("if(status in ('waiting','ready'), concat(student_id, ':', book_id), null)");
            $table->unique('active_pair', 'reservations_one_active');

            $table->index(['library_id', 'book_id', 'status', 'requested_at'], 'res_queue_idx');
            $table->index(['student_id', 'status'], 'res_student_idx');
            $table->index(['library_id', 'status', 'expires_on'], 'res_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
