<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per Razorpay order a library started. The amount is fixed here, on our server, before the order exists.
        Schema::create('online_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 20);                                   // subscription | credits
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->string('billing_cycle', 20)->nullable();
            $table->string('channel', 20)->nullable();                       // sms | whatsapp (credits)
            $table->unsignedInteger('credits')->nullable();
            $table->unsignedInteger('coins_used')->default(0);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('razorpay_order_id', 40)->unique();
            $table->string('razorpay_payment_id', 40)->nullable()->unique();
            $table->string('status', 20)->default('created');               // created | paid | failed
            $table->string('failure_reason')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->foreignId('subscription_payment_id')->nullable()->constrained('subscription_payments')->nullOnDelete();
            $table->foreignId('credit_purchase_id')->nullable()->constrained('credit_purchases')->nullOnDelete();
            $table->timestamps();

            $table->index(['library_id', 'status']);
        });

        // Webhook deliveries we already handled (Razorpay retries, so the same event can arrive more than once).
        Schema::create('razorpay_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 80)->unique();
            $table->string('event', 60);
            $table->string('order_id', 40)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('razorpay_events');
        Schema::dropIfExists('online_payments');
    }
};
