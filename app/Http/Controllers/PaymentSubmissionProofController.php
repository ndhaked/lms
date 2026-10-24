<?php

namespace App\Http\Controllers;

use App\Models\PaymentSubmission;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Payment proofs are private: only staff who handle payments, or the student who sent it, can open one. */
class PaymentSubmissionProofController extends Controller
{
    public function show(Request $request, string $library, string $submission)
    {
        // Tenant-scoped lookup: another library's submission is a 404.
        $submission = PaymentSubmission::findOrFail($submission);
        $user = $request->user();

        $isStaff = $user->can('payments.record') || $user->can('payments.view');
        $isOwner = $user->can('self.view') && Student::where('user_id', $user->id)->whereKey($submission->student_id)->exists();
        abort_unless($isStaff || $isOwner, 403);

        abort_unless($submission->proof_path && Storage::disk('local')->exists($submission->proof_path), 404);

        return Storage::disk('local')->response($submission->proof_path, null, ['Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'", 'X-Content-Type-Options' => 'nosniff']);
    }
}
