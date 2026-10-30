<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/** My profile (same rules as the web profile page): name and mobile can change; the email is the sign-in name and cannot. */
class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $request->user()->forceFill(['name' => trim($data['name']), 'phone' => $data['phone'] ? trim($data['phone']) : null])->save();

        return $this->message('Profile saved.', AuthController::userPayload($request->user()));
    }

    /** Changing the password signs every other phone out; this one stays signed in. */
    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is not correct.']);
        }

        $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();

        $current = $user->currentAccessToken()->id;
        $others = $user->tokens()->where('id', '!=', $current)->pluck('id');
        $user->devices()->whereIn('access_token_id', $others)->delete();
        $user->tokens()->whereIn('id', $others)->delete();

        return $this->message('Password changed. Other devices were signed out.');
    }
}
