<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->decimal('price_monthly', 10, 2)->nullable();
            $table->decimal('price_quarterly', 10, 2)->nullable();
            $table->decimal('price_annual', 10, 2)->nullable();
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->unsignedInteger('max_students')->nullable();   // null = unlimited
            $table->unsignedInteger('max_seats')->nullable();
            $table->unsignedInteger('max_staff')->nullable();
            $table->json('modules')->nullable();                   // enabled optional modules
            $table->unsignedSmallInteger('grace_days')->default(7);
            $table->string('status', 20)->default('active');       // active, inactive
            $table->timestamps();
        });

        // One row per subscription period of a library. Limits, price and modules are COPIED from the plan
        // when the subscription is created or changed, so editing a plan never silently alters a library.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->foreignId('next_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete(); // applies at next renewal
            $table->string('plan_name');
            $table->string('billing_cycle', 20)->default('monthly');  // monthly, quarterly, annual
            $table->string('status', 20)->default('pending');          // trial, pending, active, expired, suspended, cancelled
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedInteger('max_students')->nullable();
            $table->unsignedInteger('max_seats')->nullable();
            $table->unsignedInteger('max_staff')->nullable();
            $table->json('modules')->nullable();
            $table->unsignedSmallInteger('grace_days')->default(7);
            $table->date('starts_on')->nullable();
            $table->date('trial_ends_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['library_id', 'status']);
            $table->index('expires_on');
        });

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->string('billing_cycle', 20);
            $table->string('reference', 100);                   // bank / UPI transaction reference
            $table->decimal('amount', 10, 2);
            $table->string('method', 20);
            $table->date('paid_on');
            $table->text('note')->nullable();
            $table->string('status', 20)->default('pending');   // pending, approved, rejected, info_requested
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();             // rejection reason or the question asked
            $table->date('period_starts_on')->nullable();        // filled on approval
            $table->date('period_ends_on')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['library_id', 'status']);
        });

        Schema::create('subscription_payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_payment_id')->constrained('subscription_payments')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });

        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 30)->nullable()->unique();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_payment_id')->nullable()->constrained('subscription_payments')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('status', 20)->default('paid');      // issued, paid, void
            $table->date('issued_on');
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->string('description');
            $table->timestamps();
        });

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);                          // created, activated, extended, suspended, ...
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('data')->nullable();                    // previous/new values (dates, plan ...)
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subscription_id', 'created_at']);
        });

        // Reserved for the future online-payment integration; nothing writes here yet.
        Schema::create('payment_gateway_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_payment_id')->nullable()->constrained('subscription_payments')->nullOnDelete();
            $table->string('gateway', 30);
            $table->string('gateway_transaction_id', 100);
            $table->string('status', 30);
            $table->boolean('signature_verified')->default(false);
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'gateway_transaction_id'], 'gateway_txn_unique');   // idempotent webhook processing
        });

        Schema::create('subscription_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['subscription_settings', 'payment_gateway_transactions', 'subscription_events', 'subscription_invoices', 'subscription_payment_proofs', 'subscription_payments', 'subscriptions', 'subscription_plans'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
