<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('library_modules')) {
            Schema::create('library_modules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('library_id')->constrained()->cascadeOnDelete();
                $table->string('module', 40);
                $table->string('type', 20);                                   // addon (paid, billed with the subscription) | complimentary (free)
                $table->decimal('price_monthly', 10, 2)->nullable();          // add-on price snapshot; quarterly/annual fall back to monthly x months
                $table->decimal('price_quarterly', 10, 2)->nullable();
                $table->decimal('price_annual', 10, 2)->nullable();
                $table->date('expires_on')->nullable();                       // complimentary only; null = until revoked
                $table->string('status', 20)->default('active');              // active | revoked
                $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('reason', 500)->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->unique(['library_id', 'module']);
            });
        }

        if (! Schema::hasColumn('subscription_payments', 'breakdown')) {
            Schema::table('subscription_payments', function (Blueprint $table) {
                $table->json('breakdown')->nullable()->after('amount');        // what the amount was made of: plan + add-ons
            });
        }
        if (! Schema::hasColumn('subscription_invoices', 'lines')) {
            Schema::table('subscription_invoices', function (Blueprint $table) {
                $table->json('lines')->nullable()->after('description');
            });
        }
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', fn (Blueprint $table) => $table->dropColumn('lines'));
        Schema::table('subscription_payments', fn (Blueprint $table) => $table->dropColumn('breakdown'));
        Schema::dropIfExists('library_modules');
    }
};
