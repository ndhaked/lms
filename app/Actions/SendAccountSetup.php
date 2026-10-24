<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Password;

class SendAccountSetup
{
    /**
     * Emails a one-time link where the person chooses their own password (3-day expiry, see
     * config/auth.php). No password is ever generated, shown, or shared.
     */
    public function __invoke(User $user): void
    {
        $token = Password::broker()->createToken($user);

        $user->sendPasswordResetNotification($token);
    }
}
