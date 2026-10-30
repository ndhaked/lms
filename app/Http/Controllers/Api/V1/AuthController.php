<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Library;
use App\Models\User;
use App\Support\Entitlements;
use App\Support\LibraryRoles;
use App\Support\Tenancy;
use App\Support\Themes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in for the mobile app. One login for owners, staff and students; the response lists the libraries
 * the person belongs to and their role in each, which decides the app's workspace (staff or student).
 * Tokens are Sanctum personal access tokens: one per phone, they expire (config sanctum.expiration),
 * and they are deleted on sign-out, on password change and when the account is deactivated.
 */
class AuthController extends Controller
{
    /** At most this many signed-in phones per person; the oldest sign-in is ended first. */
    protected const MAX_TOKENS = 10;

    public function login(Request $request): JsonResponse
    {
        // no push token yet (or a placeholder like "null"): sign in without push notifications
        if (in_array($request->input('push_token'), ['', 'null', 'undefined'], true)) {
            $request->merge(['push_token' => null]);
        }
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:120'],
            // optional: register this phone for push notifications in the same call
            'push_token' => ['nullable', 'string', 'max:255', DeviceController::tokenRule($request->input('push_provider'))],
            'push_provider' => ['nullable', 'required_with:push_token', Rule::in(\App\Models\DeviceToken::PROVIDERS)],
            'platform' => ['nullable', 'required_with:push_token', Rule::in(\App\Models\DeviceToken::PLATFORMS)],
            'app_version' => ['nullable', 'string', 'max:30'],
        ]);

        $user = User::where('email', mb_strtolower(trim($data['email'])))->first();

        // One message for every failure, so the response never reveals whether an email is registered.
        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->is_active) {
            throw ValidationException::withMessages(['email' => __('These credentials do not match our records.')]);
        }
        if ($user->is_super_admin) {
            throw ValidationException::withMessages(['email' => 'Platform administrators use the web panel.']);
        }
        if ($user->accessibleLibraries()->isEmpty()) {
            throw ValidationException::withMessages(['email' => 'Your account is not linked to an active library. Contact your library.']);
        }

        // keep the number of signed-in phones bounded
        $excess = $user->tokens()->count() - self::MAX_TOKENS + 1;
        if ($excess > 0) {
            $old = $user->tokens()->orderBy('last_used_at')->orderBy('id')->limit($excess)->pluck('id');
            $user->tokens()->whereIn('id', $old)->delete();
            $user->devices()->whereIn('access_token_id', $old)->delete();
        }

        $expires = ($minutes = (int) config('sanctum.expiration')) > 0 ? now()->addMinutes($minutes) : null;
        $token = $user->createToken(mb_substr($data['device_name'], 0, 120), ['*'], $expires);

        if (! empty($data['push_token'])) {
            DeviceController::register($user, $token->accessToken->id, $data['push_token'], $data['push_provider'], $data['platform'], $data['device_name'], $data['app_version'] ?? null);
        }

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expires?->toIso8601String(),
            'user' => self::userPayload($user),
            'libraries' => self::librariesPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => self::userPayload($request->user()), 'libraries' => self::librariesPayload($request->user())]);
    }

    /** Sign out of this phone: the token and its push registration are deleted. */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        $request->user()->devices()->where('access_token_id', $token->id)->delete();
        $token->delete();

        return $this->message('Signed out.');
    }

    /** Sign out everywhere (all phones). */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->devices()->delete();
        $request->user()->tokens()->delete();

        return $this->message('Signed out of all devices.');
    }

    /** Phones where this account is signed in. */
    public function sessions(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken()->id;

        return $this->ok($request->user()->tokens()->latest('last_used_at')->get()->map(fn ($t) => [
            'id' => $t->id, 'name' => $t->name, 'current' => $t->id === $current,
            'last_used_at' => $this->time($t->last_used_at), 'created_at' => $this->time($t->created_at), 'expires_at' => $this->time($t->expires_at),
        ])->all());
    }

    public function revokeSession(Request $request, int $id): JsonResponse
    {
        $token = $request->user()->tokens()->whereKey($id)->firstOrFail();
        $request->user()->devices()->where('access_token_id', $token->id)->delete();
        $token->delete();

        return $this->message('That device was signed out.');
    }

    /** Same reset flow as the web: an email with a link. The answer is the same whether or not the email exists. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        Password::sendResetLink(['email' => mb_strtolower(trim($data['email']))]);

        return $this->message('If that email belongs to an account, we have sent a link to choose a new password.');
    }

    // ---------- payloads shared with other controllers ----------

    public static function userPayload(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone];
    }

    public static function librariesPayload(User $user): array
    {
        return $user->accessibleLibraries()->map(fn (Library $l) => self::libraryPayload($user, $l))->values()->all();
    }

    /** One library as the app needs it to choose a workspace: role names, permissions and theme. */
    public static function libraryPayload(User $user, Library $library, bool $withPermissions = false): array
    {
        $previous = getPermissionsTeamId();
        setPermissionsTeamId($library->id);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $roles = $user->getRoleNames()->values()->all();
        $permissions = $withPermissions ? $user->getAllPermissions()->pluck('name')->sort()->values()->all() : null;
        setPermissionsTeamId($previous);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        $theme = Tenancy::run($library, fn () => Themes::key());
        $staff = collect($roles)->contains(fn ($r) => $r !== LibraryRoles::STUDENT);

        return array_filter([
            'id' => $library->id,
            'slug' => $library->slug,
            'name' => $library->name,
            'code' => $library->code,
            'status' => $library->status,
            'roles' => $roles,
            'workspace' => $staff ? 'staff' : 'student',   // which set of app screens to open
            'theme' => $theme,
            'permissions' => $permissions,
        ], fn ($v) => $v !== null);
    }
}
