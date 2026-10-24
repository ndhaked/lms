<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gap-free, per-library number sequences (receipts, refunds). Rows are locked while incrementing.
        Schema::create('library_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->unsignedBigInteger('value')->default(0);
            $table->unique(['library_id', 'name']);
        });

        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('seat_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('checked_in_at');
            $table->timestamp('checked_out_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            // Set only while the student is inside; the unique key makes a duplicate active check-in impossible.
            $table->unsignedBigInteger('active_student_id')->nullable();
            $table->timestamps();

            $table->unique('active_student_id');
            $table->index(['library_id', 'checked_in_at']);
            $table->index(['library_id', 'student_id']);
        });

        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_id')->constrained('attendance')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 500);
            $table->json('before');
            $table->json('after');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('receipt_no', 30);
            $table->decimal('amount', 10, 2);
            $table->string('method', 20); // cash, upi, bank_transfer, card, other
            $table->string('reference', 100)->nullable();
            $table->date('paid_on');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('completed'); // completed, reversed
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reversal_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['library_id', 'receipt_no']);
            $table->unique(['library_id', 'idempotency_key']);
            $table->index(['library_id', 'paid_on']);
            $table->index(['library_id', 'student_id']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_membership_id')->constrained('student_memberships')->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->index(['library_id', 'student_membership_id']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_membership_id')->constrained('student_memberships')->restrictOnDelete();
            $table->string('refund_no', 30);
            $table->decimal('amount', 10, 2);
            $table->string('method', 20);
            $table->string('reason', 500);
            $table->date('refunded_on');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['library_id', 'refund_no']);
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['library_id', 'name']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->string('description');
            $table->decimal('amount', 10, 2);
            $table->date('expense_date');
            $table->string('method', 20);
            $table->string('vendor')->nullable();
            $table->string('attachment_path')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['library_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        foreach (['expenses', 'expense_categories', 'refunds', 'payment_allocations', 'payments', 'attendance_corrections', 'attendance', 'library_counters'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
