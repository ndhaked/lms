<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_submissions')) {
            return;
        }

        Schema::create('payment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_membership_id')->nullable()->constrained('student_memberships')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->date('paid_on');
            $table->string('proof_path')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20)->default('pending');          // pending | approved | rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamps();

            $table->index(['library_id', 'status', 'id'], 'ps_library_status_idx');
            $table->index(['student_id', 'status'], 'ps_student_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_submissions');
    }
};
