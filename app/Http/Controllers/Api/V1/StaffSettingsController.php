<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\SendAccountSetup;
use App\Models\AuditLog;
use App\Models\StudyHall;
use App\Models\User;
use App\Support\Entitlements;
use App\Support\LibraryRoles;
use App\Support\LibrarySettings;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Staff and roles (staff.manage) and the main library settings (settings.manage), with the web's safety rules:
 * only an owner hands out the Owner role, a library always keeps an active owner, nobody can lock themselves out.
 * Editing roles' permissions and the detailed lending rules stay on the web panel.
 */
class StaffSettingsController extends Controller
{
    protected function roles()
    {
        return Role::where('guard_name', 'web')->where('library_id', Tenancy::currentId())->orderBy('name')->get();
    }

    protected function member(int $id): User
    {
        return $this->library()->users()->where('users.id', $id)->firstOrFail();
    }

    protected function activeOwners(): int
    {
        return $this->library()->users()->wherePivot('is_active', true)->get()->filter(fn ($u) => $u->hasRole(LibraryRoles::OWNER))->count();
    }

    public function staff(Request $request): JsonResponse
    {
        $this->authorizePermission('staff.manage');
        $q = trim((string) $request->query('q', ''));
        $halls = StudyHall::get(['id', 'name', 'supervisor_id']);

        $staff = $this->library()->users()->with('roles')->orderBy('users.name')
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('users.name', 'like', "%{$q}%")->orWhere('users.email', 'like', "%{$q}%")))
            ->get()->reject(fn ($u) => $u->roles->isNotEmpty() && $u->roles->every(fn ($r) => $r->name === LibraryRoles::STUDENT))->values();

        return $this->ok([
            'staff' => $staff->map(fn ($u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'active' => (bool) $u->pivot->is_active,
                'roles' => $u->roles->pluck('name')->values()->all(), 'you' => $u->id === auth()->id(),
                'halls' => $halls->where('supervisor_id', $u->id)->map(fn ($h) => ['id' => $h->id, 'name' => $h->name])->values()->all(),
            ])->all(),
            'roles' => $this->roles()->map(fn ($r) => ['name' => $r->name, 'permissions' => $r->permissions()->count()])->all(),
            'limit' => Entitlements::subscription()?->limit('staff'),
        ]);
    }

    public function storeStaff(Request $request): JsonResponse
    {
        return $this->saveStaff($request, null);
    }

    public function updateStaff(Request $request, int $user): JsonResponse
    {
        return $this->saveStaff($request, $user);
    }

    protected function saveStaff(Request $request, ?int $id): JsonResponse
    {
        $this->authorizePermission('staff.manage');
        $this->writable();
        $d = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [$id ? 'nullable' : 'required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in($this->roles()->pluck('name')->all())],
            'halls' => ['nullable', 'array'],
            'halls.*' => [Rule::exists('study_halls', 'id')->where('library_id', Tenancy::currentId())],
        ], ['roles.min' => 'Choose at least one role.']);
        $roles = array_values($d['roles']);
        $halls = array_map('intval', $d['halls'] ?? []);

        $touchesOwner = in_array(LibraryRoles::OWNER, $roles, true) || ($id && $this->member($id)->hasRole(LibraryRoles::OWNER));
        abort_if($touchesOwner && ! auth()->user()->hasRole(LibraryRoles::OWNER), 422, 'Only a library owner can give or change the Owner role.');

        $isNew = false;
        if ($id) {
            $user = $this->member($id);
            $removingOwner = $user->hasRole(LibraryRoles::OWNER) && ! in_array(LibraryRoles::OWNER, $roles, true);
            abort_if($removingOwner && $user->pivot->is_active && $this->activeOwners() <= 1, 422, 'A library must keep at least one active owner.');
            $user->update(['name' => $d['name'], 'phone' => $d['phone'] ?? null]);
        } else {
            $email = mb_strtolower(trim($d['email']));
            $existing = User::where('email', $email)->first();
            abort_if($existing && $this->library()->users()->where('users.id', $existing->id)->exists(), 422, 'This person already has access to the library.');
            abort_if($existing?->is_super_admin, 422, 'That email belongs to a platform administrator.');
            Entitlements::assertCanAdd('staff');
            $user = $existing ?? User::create(['name' => $d['name'], 'email' => $email, 'phone' => $d['phone'] ?? null, 'password' => Str::random(40)]);
            $isNew = ! $existing;
            $this->library()->users()->attach($user->id, ['is_active' => true]);
        }

        DB::transaction(function () use ($user, $roles, $halls, $id) {
            $before = $user->roles()->pluck('name')->all();
            $user->syncRoles($roles);
            StudyHall::where('supervisor_id', $user->id)->whereNotIn('id', $halls)->update(['supervisor_id' => null]);
            StudyHall::whereIn('id', $halls)->update(['supervisor_id' => $user->id]);
            AuditLog::record($id ? 'staff.updated' : 'staff.added', $user, ['roles_before' => $before, 'roles_after' => $roles, 'via' => 'app']);
        });

        if ($isNew) {
            app(SendAccountSetup::class)($user);
        }

        return $this->message($isNew ? "Added. A setup email was sent to {$user->email}." : 'Saved.', ['id' => $user->id], $id ? 200 : 201);
    }

    /** Turn someone's access to this library off or on. Turning it off also signs them out of the app for this library. */
    public function access(Request $request, int $user): JsonResponse
    {
        $this->authorizePermission('staff.manage');
        $this->writable();
        $d = $request->validate(['active' => ['required', 'boolean']]);
        $u = $this->member($user);
        abort_if($u->id === auth()->id(), 422, 'You cannot disable your own access.');
        $enable = (bool) $d['active'];
        if (! $enable && $u->hasRole(LibraryRoles::OWNER)) {
            abort_if($this->activeOwners() <= 1, 422, 'A library must keep at least one active owner.');
            abort_unless(auth()->user()->hasRole(LibraryRoles::OWNER), 422, 'Only a library owner can disable an owner.');
        }
        $this->library()->users()->updateExistingPivot($u->id, ['is_active' => $enable]);
        AuditLog::record($enable ? 'staff.enabled' : 'staff.disabled', $u);

        return $this->message($enable ? 'Access restored.' : 'Access disabled.');
    }

    public function resetAccess(int $user): JsonResponse
    {
        $this->authorizePermission('staff.manage');
        $this->writable();
        $u = $this->member($user);
        app(SendAccountSetup::class)($u);
        AuditLog::record('staff.access_reset_sent', $u, ['to' => $u->email]);

        return $this->message("A password link was sent to {$u->email}.");
    }

    // ---------- settings ----------

    public function settings(): JsonResponse
    {
        $this->authorizePermission('settings.manage');
        $l = $this->library();
        $s = LibrarySettings::all();

        return $this->ok([
            'profile' => ['name' => $l->name, 'email' => $l->email, 'phone' => $l->phone, 'address' => $l->address, 'timezone' => $l->timezone, 'currency' => $l->currency,
                'opening_time' => $l->opening_time ? substr($l->opening_time, 0, 5) : null, 'closing_time' => $l->closing_time ? substr($l->closing_time, 0, 5) : null,
                'logo_url' => $l->logo_path ? asset('storage/'.$l->logo_path) : null],
            'general' => [
                'working_days' => $s['working_days'] ?? null, 'holidays' => $s['holidays'] ?? [],
                'renewal_window_days' => (int) ($s['renewal_window_days'] ?? 7), 'renewal_starts_after_expiry' => (bool) ($s['renewal_starts_after_expiry'] ?? true),
                'attendance_grace_minutes' => (int) ($s['attendance_grace_minutes'] ?? 15), 'attendance_require_membership' => (bool) ($s['attendance_require_membership'] ?? true),
                'seat_prefix' => (string) ($s['seat_prefix'] ?? ''), 'seat_padding' => (int) ($s['seat_padding'] ?? 2),
            ],
        ]);
    }

    /** Library profile; the logo is a multipart "logo" image. */
    public function updateProfile(Request $request): JsonResponse
    {
        $this->authorizePermission('settings.manage');
        $this->writable();
        $d = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'], 'timezone' => ['required', 'timezone:all'], 'currency' => ['required', 'string', 'max:8'],
            'opening_time' => ['nullable', 'date_format:H:i'], 'closing_time' => ['nullable', 'date_format:H:i', 'after:opening_time'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024'],
        ]);
        $l = $this->library();
        $l->fill(array_map(fn ($v) => $v === '' ? null : $v, collect($d)->except('logo')->all()));
        if ($request->hasFile('logo')) {
            if ($l->logo_path) {
                Storage::disk('public')->delete($l->logo_path);
            }
            $l->logo_path = $request->file('logo')->store('logos', 'public');
        }
        $changed = array_keys($l->getDirty());
        $l->save();
        AuditLog::record('settings.profile_updated', $l, ['changed' => $changed, 'via' => 'app']);

        return $this->message('Library details saved.');
    }

    public function updateGeneral(Request $request): JsonResponse
    {
        $this->authorizePermission('settings.manage');
        $this->writable();
        $d = $request->validate([
            'working_days' => ['sometimes', 'array'], 'working_days.*' => ['in:1,2,3,4,5,6,7'],
            'renewal_window_days' => ['sometimes', 'integer', 'between:1,90'],
            'renewal_starts_after_expiry' => ['sometimes', 'boolean'],
            'attendance_grace_minutes' => ['sometimes', 'integer', 'between:0,240'],
            'attendance_require_membership' => ['sometimes', 'boolean'],
            'seat_prefix' => ['sometimes', 'nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9\-_.\/ ]*$/'],
            'seat_padding' => ['sometimes', 'integer', 'between:1,5'],
        ]);
        if (array_key_exists('working_days', $d)) {
            $d['working_days'] = array_values(array_map('intval', $d['working_days']));
        }
        if (array_key_exists('seat_prefix', $d)) {
            $d['seat_prefix'] = (string) $d['seat_prefix'];
        }
        LibrarySettings::update($d);
        AuditLog::record('settings.updated', null, ['changed' => array_keys($d), 'via' => 'app']);

        return $this->message('Settings saved.');
    }
}
