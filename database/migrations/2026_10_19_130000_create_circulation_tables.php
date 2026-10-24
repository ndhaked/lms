<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('students', 'member_type')) {
            Schema::table('students', function (Blueprint $table) {
                $table->string('member_type', 12)->default('student');          // student | staff | faculty: picks the loan rules
            });
        }

        if (! Schema::hasTable('loans')) {
            Schema::create('loans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('library_id')->constrained()->cascadeOnDelete();
                $table->string('loan_no', 30);
                $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
                $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('issued_at');
                $table->string('notes', 500)->nullable();
                $table->timestamps();

                $table->unique(['library_id', 'loan_no'], 'loans_no_unique');
                $table->index(['library_id', 'student_id'], 'loans_student_idx');
            });
        }

        if (! Schema::hasTable('loan_items')) {
            Schema::create('loan_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('library_id')->constrained()->cascadeOnDelete();
                $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
                $table->foreignId('book_id')->constrained('books')->restrictOnDelete();
                $table->foreignId('book_copy_id')->constrained('book_copies')->restrictOnDelete();
                $table->timestamp('issued_at');
                $table->date('due_on');
                $table->timestamp('returned_at')->nullable();
                $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('return_receipt_no', 30)->nullable();
                $table->unsignedTinyInteger('renewals')->default(0);
                $table->timestamp('last_renewed_at')->nullable();
                $table->string('status', 12)->default('issued');                 // issued | returned | lost | reversed
                $table->string('return_condition', 10)->nullable();              // good | damaged | lost
                $table->timestamps();

                // A copy can be out on loan only once at a time, enforced by the database itself.
                $table->unsignedBigInteger('active_copy_id')->nullable()->virtualAs("if(status = 'issued', book_copy_id, null)");
                $table->unique('active_copy_id', 'loan_items_one_active_copy');

                $table->index(['library_id', 'status', 'due_on'], 'li_status_due_idx');
                $table->index(['student_id', 'status'], 'li_student_status_idx');
                $table->index(['library_id', 'returned_at'], 'li_returned_idx');
                $table->index('return_receipt_no', 'li_return_receipt_idx');
            });
        }

        if (! Schema::hasTable('fines')) {
            Schema::create('fines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('library_id')->constrained()->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
                $table->foreignId('loan_item_id')->nullable()->constrained('loan_items')->nullOnDelete();
                $table->string('type', 10);                                       // overdue | lost | damaged | manual
                $table->unsignedSmallInteger('days')->nullable();
                $table->decimal('amount', 10, 2);
                $table->decimal('waived_amount', 10, 2)->default(0);
                $table->decimal('paid_amount', 10, 2)->default(0);
                $table->string('status', 10)->default('pending');                // pending | paid | waived
                $table->string('note', 255)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('settled_at')->nullable();
                $table->string('waive_reason', 500)->nullable();
                $table->string('method', 20)->nullable();
                $table->string('reference', 100)->nullable();
                $table->string('receipt_no', 30)->nullable();
                $table->date('paid_on')->nullable();
                $table->timestamps();

                $table->unique(['library_id', 'receipt_no'], 'fines_receipt_unique');
                $table->index(['library_id', 'status'], 'fines_status_idx');
                $table->index(['student_id', 'status'], 'fines_student_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fines');
        Schema::dropIfExists('loan_items');
        Schema::dropIfExists('loans');
        Schema::table('students', fn (Blueprint $table) => $table->dropColumn('member_type'));
    }
};
