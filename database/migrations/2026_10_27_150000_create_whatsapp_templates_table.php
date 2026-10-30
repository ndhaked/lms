<?php

use App\Models\Library;
use App\Support\MessageTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->text('message');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['library_id', 'sort']);
        });

        // libraries that already exist start with the same ready-made set a new one gets
        foreach (Library::all() as $library) {
            MessageTemplates::seedWhatsapp($library);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
