<?php

namespace App\Actions\Books;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookCopyEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Archive, restore and delete titles. Anything with history is archived, never deleted. */
class ManageBookLifecycle
{
    public function archive(Book $book): Book
    {
        SaveBook::assertAllowed($book->type);

        if ($book->copies()->whereIn('status', [BookCopy::ISSUED, BookCopy::RESERVED])->exists()) {
            throw new BusinessRuleException('Some copies are issued or reserved. Wait until they are back before archiving.');
        }

        $book->forceFill(['status' => 'archived'])->save();
        AuditLog::record('book.archived', $book, ['title' => $book->title]);

        return $book;
    }

    public function restore(Book $book): Book
    {
        SaveBook::assertAllowed($book->type);

        $book->forceFill(['status' => 'active'])->save();
        AuditLog::record('book.restored', $book, ['title' => $book->title]);

        return $book;
    }

    /** A title can be deleted only if it has never been used: no copy was issued, no e-book was opened. */
    public static function canDelete(Book $book): bool
    {
        if ($book->isEbook()) {
            return ! $book->accessLogs()->exists();
        }

        return ! BookCopyEvent::whereIn('book_copy_id', $book->copies()->select('id'))->where('type', '!=', 'acquired')->exists()
            && ! $book->copies()->where('status', '!=', BookCopy::AVAILABLE)->exists();
    }

    public function delete(Book $book): void
    {
        SaveBook::assertAllowed($book->type);

        if (! self::canDelete($book)) {
            throw new BusinessRuleException('This title has history (issues, status changes or readers), so it cannot be deleted. Archive it instead.');
        }

        $files = array_filter([$book->cover_path, $book->ebook_path]);
        $title = $book->title;

        DB::transaction(function () use ($book, $title) {
            AuditLog::record('book.deleted', null, ['title' => $title, 'type' => $book->type, 'isbn' => $book->isbn]);
            $book->delete();   // copies and their "acquired" events go with it
        });

        foreach ($files as $path) {
            Storage::disk('local')->delete($path);
        }
    }
}
