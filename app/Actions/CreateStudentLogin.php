<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Student;
use App\Models\User;
use App\Support\LibraryRoles;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateStudentLogin
{
    /**
     * Gives a student a login (Student role in this library only). The student chooses their own password
     * through the emailed setup link; nobody is handed a password.
     */
    public function __invoke(Student $student): User
    {
        abort_unless(auth()->user()?->can('students.update'), 403);

        if ($student->user_id) {
            throw new BusinessRuleException('This student already has a login.');
        }
        if (blank($student->email)) {
            throw new BusinessRuleException('Add the student\'s email address first. It becomes their login.');
        }
        if ($student->status !== Student::ACTIVE) {
            throw new BusinessRuleException('Only active students can be given a login.');
        }

        $library = Tenancy::library();

        $user = DB::transaction(function () use ($student, $library) {
            $user = User::where('email', $student->email)->first();

            if ($user) {
                // Someone who works at, or studies at, this library must not be turned into a student by accident.
                if ($library->users()->where('users.id', $user->id)->exists()) {
                    throw new BusinessRuleException('That email already has access to this library as a team member.');
                }
            } else {
                $user = User::create(['name' => $student->name, 'email' => $student->email, 'phone' => $student->phone, 'password' => Str::random(40)]);
            }

            $library->users()->attach($user->id, ['is_active' => true]);
            $user->assignRole(LibraryRoles::STUDENT);
            $student->forceFill(['user_id' => $user->id])->save();

            AuditLog::record('student.login_created', $student, ['user_id' => $user->id]);

            return $user;
        });

        // A brand-new login needs a password; an existing one keeps its own.
        if ($user->wasRecentlyCreated) {
            app(SendAccountSetup::class)($user);
        }

        return $user;
    }
}
