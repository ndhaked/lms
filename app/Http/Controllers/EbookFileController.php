<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\EbookAccessLog;
use App\Models\Student;
use App\Models\StudentMembership;
use App\Support\Entitlements;
use App\Support\LibrarySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * E-book files live on the private disk. They are only served to staff who may open e-books, or to students
 * of this library (with a running membership, if the library requires it). Every opening is logged for usage stats.
 */
class EbookFileController extends Controller
{
    public function show(Request $request, string $library, string $book, string $action = 'view')
    {
        abort_unless(in_array($action, ['view', 'download'], true), 404);
        abort_unless(Entitlements::hasModule('ebooks'), 403, 'E-books are not enabled for this library.');

        // Tenant-scoped: another library's id is a 404.
        $book = Book::where('type', Book::EBOOK)->findOrFail($book);
        $user = $request->user();

        $isStaff = $user->can('ebooks.view');
        $manager = $user->can('ebooks.manage');
        $student = null;

        if (! $isStaff) {
            abort_unless($user->can('ebooks.read') && $user->can('self.view'), 403);
            $student = Student::where('user_id', $user->id)->first();
            abort_unless($student, 403, 'No student record is linked to your login.');
            if (LibrarySettings::get('ebook_require_membership')) {
                abort_unless(StudentMembership::current()->where('student_id', $student->id)->exists(), 403, 'You need a running membership to read e-books.');
            }
        }

        abort_if($book->isArchived() && ! $manager, 404);
        abort_if($book->licenceExpired() && ! $manager, 403, 'The licence for this e-book has expired.');

        // A title that only has a link: log it and send the reader there.
        if (! $book->hasFile()) {
            abort_unless($book->external_url, 404);
            $this->log($book, $user->id, $student?->id, 'view');

            return redirect()->away($book->external_url);
        }

        abort_unless(Storage::disk('local')->exists($book->ebook_path), 404);

        $inline = $action === 'view' && $book->ebook_mime === 'application/pdf';
        if (! $inline && ! $book->allow_download && ! $manager) {
            abort(403, $action === 'view' ? 'This e-book can only be read online and this format cannot be shown in the browser.' : 'Downloading is turned off for this e-book.');
        }
        if ($action === 'download' && ! $book->allow_download && ! $manager) {
            abort(403, 'Downloading is turned off for this e-book.');
        }

        $this->log($book, $user->id, $student?->id, $inline ? 'view' : 'download');

        return Storage::disk('local')->response($book->ebook_path, $book->ebook_name ?: 'ebook', [
            'Content-Type' => $book->ebook_mime ?: 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.addslashes(preg_replace('/[^\w .\-()]/u', '_', $book->ebook_name ?: 'ebook')).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    protected function log(Book $book, int $userId, ?int $studentId, string $action): void
    {
        EbookAccessLog::create(['book_id' => $book->id, 'user_id' => $userId, 'student_id' => $studentId, 'action' => $action]);
    }
}
