<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('book_categories')) {
            Schema::create('book_categories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('library_id')->constrained()->cascadeOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('book_categories')->restrictOnDelete();
                $table->string('name', 100);
                $table->timestamps();

                $table->index(['library_id', 'parent_id', 'name'], 'bc_library_name_idx');
            });
        }

        if (! Schema::hasTable('books')) {
            Schema::create('books', function (Blueprint $table) {
                $table->id();
                $table->foreignId('library_id')->constrained()->cascadeOnDelete();
                $table->string('type', 10)->default('physical');             // physical | ebook
                $table->string('title');
                $table->string('subtitle')->nullable();
                $table->string('isbn', 20)->nullable();                       // digits only (X allowed last), no hyphens
                $table->string('author')->nullable();
                $table->string('publisher')->nullable();
                $table->string('edition', 50)->nullable();
                $table->string('language', 40)->nullable();
                $table->unsignedSmallInteger('publication_year')->nullable();
                $table->foreignId('category_id')->nullable()->constrained('book_categories')->nullOnDelete();
                $table->json('tags')->nullable();
                $table->text('description')->nullable();
                $table->string('cover_path')->nullable();

                // physical books: where they live, and defaults for new copies
                $table->string('shelf', 50)->nullable();
                $table->string('rack', 50)->nullable();
                $table->string('section', 50)->nullable();
                $table->string('location', 100)->nullable();
                $table->decimal('purchase_price', 10, 2)->nullable();
                $table->string('supplier', 150)->nullable();
                $table->date('purchased_on')->nullable();

                // e-books: the file (private disk) or an external link, and the licence
                $table->string('ebook_path')->nullable();
                $table->string('ebook_name')->nullable();
                $table->string('ebook_mime', 100)->nullable();
                $table->unsignedBigInteger('ebook_size')->nullable();
                $table->string('external_url', 500)->nullable();
                $table->date('licence_expires_on')->nullable();
                $table->boolean('allow_download')->default(true);

                $table->string('status', 10)->default('active');              // active | archived
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['library_id', 'type', 'status'], 'books_library_type_idx');
                $table->index(['library_id', 'isbn'], 'books_library_isbn_idx');
                $table->index(['library_id', 'title'], 'books_library_title_idx');
                $table->index(['library_id', 'category_id'], 'books_library_category_idx');
            });
        }

        if (! Schema::hasTable('book_copies')) {
            Schema::create('book_copies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('library_id')->constrained()->cascadeOnDelete();
                $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
                $table->string('accession_no', 40);
                $table->string('barcode', 60);
                $table->string('status', 15)->default('available');          // available | issued | reserved | lost | damaged | withdrawn
                $table->string('location', 100)->nullable();                  // overrides the title's location
                $table->decimal('purchase_price', 10, 2)->nullable();
                $table->string('supplier', 150)->nullable();
                $table->date('purchased_on')->nullable();
                $table->string('note', 255)->nullable();
                $table->date('withdrawn_on')->nullable();
                $table->timestamps();

                $table->unique(['library_id', 'accession_no'], 'copies_accession_unique');
                $table->unique(['library_id', 'barcode'], 'copies_barcode_unique');
                $table->index(['book_id', 'status'], 'copies_book_status_idx');
            });
        }

        // acquisition, status changes, disposal, audits: nothing about a copy is forgotten
        if (! Schema::hasTable('book_copy_events')) {
            Schema::create('book_copy_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('library_id')->constrained()->cascadeOnDelete();
                $table->foreignId('book_copy_id')->constrained('book_copies')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('type', 30);
                $table->string('from_status', 15)->nullable();
                $table->string('to_status', 15)->nullable();
                $table->string('note', 500)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['book_copy_id', 'id'], 'bce_copy_idx');
            });
        }

        if (! Schema::hasTable('ebook_access_logs')) {
            Schema::create('ebook_access_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('library_id')->constrained()->cascadeOnDelete();
                $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
                $table->string('action', 10);                                  // view | download
                $table->timestamp('created_at')->useCurrent();

                $table->index(['library_id', 'created_at'], 'eal_library_time_idx');
                $table->index(['book_id', 'created_at'], 'eal_book_time_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['ebook_access_logs', 'book_copy_events', 'book_copies', 'books', 'book_categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
