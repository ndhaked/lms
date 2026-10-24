<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_audits')) {
            Schema::create('inventory_audits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('library_id')->constrained()->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('status', 12)->default('open');                 // open | completed | cancelled
                $table->string('scope_type', 12)->default('all');              // all | category | shelf
                $table->string('scope_value', 100)->nullable();
                $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('started_at');
                $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('closed_at')->nullable();
                $table->unsignedInteger('expected_count')->default(0);
                $table->unsignedInteger('found_count')->default(0);
                $table->unsignedInteger('missing_count')->default(0);
                $table->unsignedInteger('unexpected_count')->default(0);
                $table->json('unknown_codes')->nullable();                      // scanned codes that match no copy at all
                $table->string('note', 500)->nullable();
                $table->timestamps();

                // Only one stock-take can be open at a time, enforced by the database.
                $table->unsignedBigInteger('open_lock')->nullable()->virtualAs("if(status = 'open', library_id, null)");
                $table->unique('open_lock', 'audits_one_open');
                $table->index(['library_id', 'status'], 'audits_status_idx');
            });
        }

        if (! Schema::hasTable('inventory_audit_items')) {
            Schema::create('inventory_audit_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('library_id')->constrained()->cascadeOnDelete();
                $table->foreignId('inventory_audit_id')->constrained('inventory_audits')->cascadeOnDelete();
                $table->foreignId('book_copy_id')->constrained('book_copies')->cascadeOnDelete();
                $table->boolean('expected')->default(true);                     // the register said it should be on the shelf
                $table->string('status_at_start', 15);
                $table->timestamp('scanned_at')->nullable();
                $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();

                $table->unique(['inventory_audit_id', 'book_copy_id'], 'audit_items_unique');
                $table->index(['inventory_audit_id', 'expected', 'scanned_at'], 'audit_items_state_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_audit_items');
        Schema::dropIfExists('inventory_audits');
    }
};
