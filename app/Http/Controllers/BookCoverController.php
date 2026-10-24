<?php

namespace App\Http\Controllers;

use App\Models\Book;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Cover images are served through the app (private disk) to anyone in the library who can see books. */
class BookCoverController extends Controller
{
    public function show(Request $request, string $library, string $book)
    {
        $user = $request->user();
        abort_unless($user->can('books.view') || $user->can('ebooks.view') || $user->can('ebooks.read'), 403);

        $book = Book::findOrFail($book);   // tenant-scoped
        abort_unless($book->cover_path && Storage::disk('local')->exists($book->cover_path), 404);

        return Storage::disk('local')->response($book->cover_path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
