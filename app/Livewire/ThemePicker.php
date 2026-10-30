<?php

namespace App\Livewire;

use App\Support\LibrarySettings;
use App\Support\Themes;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Choose Theme" box in the panel header.
 * Library scope: a theme saved for the current library, for everyone who uses it.
 * Admin scope: the platform admin's own theme for the admin panel.
 */
class ThemePicker extends Component
{
    public bool $open = false;

    #[Locked]
    public string $scope = 'library';

    public function choose(string $key): void
    {
        $user = auth()->user();

        if ($this->scope === 'admin') {
            abort_unless($user->is_super_admin && $user->is_active, 403);
            abort_unless(isset(Themes::ALL[$key]), 404);

            $user->forceFill(['admin_theme' => $key])->save();

            return;
        }

        abort_unless($user->can('settings.manage'), 403);
        abort_unless(in_array($key, Themes::pickable(Themes::key()), true), 404);

        LibrarySettings::update(['theme' => $key]);
    }

    public function render()
    {
        $admin = $this->scope === 'admin';

        return view('livewire.theme-picker', [
            'current' => $admin ? Themes::adminKey(auth()->user()) : Themes::key(),
            'choices' => $admin ? array_keys(Themes::ALL) : Themes::pickable(Themes::key()),
            'note' => $admin ? 'Applies to your admin panel only.' : 'Applies to this library only, for everyone who uses it.',
        ]);
    }
}
