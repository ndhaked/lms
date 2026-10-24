<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SessionController extends Controller
{
    /** Where a signed-in person lands: platform admins on /admin, everyone else in their library. */
    public function home(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->is_super_admin) {
            return redirect()->route('admin.dashboard');
        }

        $libraries = $user->accessibleLibraries();

        if ($libraries->count() === 1) {
            return redirect()->route('app.dashboard', ['library' => $libraries->first()->slug]);
        }

        return redirect()->route('library.choose');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
