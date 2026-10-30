<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('libraries', function (Blueprint $table) {
            $table->foreignId('referred_by_id')->nullable()->constrained('libraries')->nullOnDelete();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->string('referral_code', 30)->nullable();      // a library's code (LIB-0001) from a shared link
        });

        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->unsignedInteger('coins_used')->default(0);     // referral coins redeemed against this payment
        });

        // Coin ledger: positive rows earn, negative rows spend. A library's balance is the sum.
        Schema::create('referral_coins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->integer('coins');
            $table->string('kind', 20);                            // earned | redeemed | returned | adjustment
            $table->string('description');
            $table->foreignId('referred_library_id')->nullable()->constrained('libraries')->nullOnDelete();
            $table->foreignId('subscription_payment_id')->nullable()->constrained('subscription_payments')->nullOnDelete();
            $table->timestamps();

            $table->index(['library_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_coins');
        Schema::table('subscription_payments', fn (Blueprint $t) => $t->dropColumn('coins_used'));
        Schema::table('leads', fn (Blueprint $t) => $t->dropColumn('referral_code'));
        Schema::table('libraries', function (Blueprint $t) {
            $t->dropConstrainedForeignId('referred_by_id');
        });
    }
};
