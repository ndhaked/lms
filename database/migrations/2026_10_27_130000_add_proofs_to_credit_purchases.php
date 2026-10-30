<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_purchases', function (Blueprint $table) {
            $table->text('note')->nullable();
        });

        Schema::create('credit_purchase_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_purchase_id')->constrained()->cascadeOnDelete();
            $table->string('path');               // private disk
            $table->string('original_name', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_purchase_proofs');
        Schema::table('credit_purchases', fn (Blueprint $table) => $table->dropColumn('note'));
    }
};
