<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leads')) {
            return;
        }

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);                                       // trial (claim the free months) | demo (book a demo)
            $table->string('name', 100);
            $table->string('email', 150);
            $table->string('phone', 30);
            $table->string('library_name', 120);
            $table->string('city', 80)->nullable();
            $table->string('students', 20)->nullable();                        // size band
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->date('preferred_date')->nullable();                        // demos
            $table->string('preferred_time', 20)->nullable();
            $table->text('message')->nullable();
            $table->string('status', 12)->default('new');                      // new | contacted | converted | closed
            $table->foreignId('library_id')->nullable()->constrained('libraries')->nullOnDelete();   // set when a library was created from it
            $table->text('notes')->nullable();                                 // internal
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 200)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['email', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
