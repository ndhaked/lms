<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\Library;
use App\Models\LibraryNote;
use App\Models\User;
use App\Support\LibraryRoles;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisterLibrary
{
    /**
     * @param  array<string, mixed>  $data  library attributes (name, address, phone, email, status ...)
     * @param  array{name: string, email: string, phone?: ?string, password?: ?string}  $owner
     * @param  bool  $sendSetup  email the owner a secure password-setup link (skipped for existing logins)
     */
    public function __invoke(array $data, array $owner, ?string $notes = null, bool $sendSetup = false, ?array $subscription = null): Library
    {
        [$library, $user, $isNew] = DB::transaction(function () use ($data, $owner, $notes, $subscription) {
            $library = Library::create($data);
            if ($library->status === Library::ACTIVE) {
                $library->forceFill(['activated_at' => now()])->save();
            }

            app(ProvisionLibraryRoles::class)($library);

            // Reuse an existing login: one person may own or work in several libraries.
            $user = User::where('email', $owner['email'])->first();
            $isNew = ! $user;
            if ($isNew) {
                // Unknown random password: the owner sets their own through the setup link.
                $user = User::create([
                    'name' => $owner['name'],
                    'email' => $owner['email'],
                    'phone' => $owner['phone'] ?? null,
                    'password' => $owner['password'] ?? Str::random(40),
                ]);
            }

            $library->users()->syncWithoutDetaching([$user->id => ['is_active' => true]]);
            $library->forceFill(['owner_id' => $user->id])->save();

            Tenancy::run($library, fn () => $user->assignRole(LibraryRoles::OWNER));

            if (filled($notes) && auth()->check()) {
                LibraryNote::create(['library_id' => $library->id, 'user_id' => auth()->id(), 'body' => $notes]);
            }

            // Optional initial subscription: ['plan' => SubscriptionPlan, 'cycle' => 'monthly', 'trial' => bool]
            if ($subscription && ($subscription['plan'] ?? null)) {
                app(\App\Actions\Billing\CreateSubscription::class)(
                    $library, $subscription['plan'], $subscription['cycle'] ?? 'monthly', (bool) ($subscription['trial'] ?? false), authorize: false,
                );
            }

            AuditLog::record('library.registered', $library, ['owner_id' => $user->id, 'status' => $library->status], $library->id);

            return [$library, $user, $isNew];
        });

        // Outside the transaction: a mail failure must not undo the registration.
        if ($sendSetup && $isNew) {
            app(SendAccountSetup::class)($user);
        }

        return $library;
    }
}
