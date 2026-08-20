<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Account-level settings (Profile/Appearance/Security) — replaces the
 * previously-broken Volt/Flux pages at resources/views/pages/settings/⚡*.
 * Behavior ported 1:1 from those components; validation reuses the same
 * framework-agnostic traits they used, so the rules are identical, not
 * re-guessed.
 */
class SettingsController extends Controller
{
    use ProfileValidationRules, PasswordValidationRules;

    public function profile(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Settings/Profile', [
            'user' => $user->only(['id', 'name', 'email']),
            'mustVerifyEmail' => $user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail,
            'hasVerifiedEmail' => $user->hasVerifiedEmail(),
            'status' => session('status'),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate($this->profileRules($user->id));

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return back()->with('success', 'Profile updated.');
    }

    public function destroyAccount(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        $user = $request->user();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $user->delete();

        return redirect('/');
    }

    public function appearance(): Response
    {
        // Purely client-side (localStorage + prefers-color-scheme, see
        // resources/js/Hooks/useTheme.js) — no server state to send.
        return Inertia::render('Settings/Appearance');
    }

    public function security(Request $request, DisableTwoFactorAuthentication $disableTwoFactorAuthentication): Response
    {
        $user = $request->user();
        $canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        // Preserved from the original Volt component's mount(): a 2FA setup
        // that was started but never confirmed gets cleaned up on page load.
        // Safe to keep even though the enable flow isn't exposed in this UI
        // (see Security.jsx) — DisableTwoFactorAuthentication only does a raw
        // forceFill, no dependency on the missing TwoFactorAuthenticatable
        // trait.
        if ($canManageTwoFactor && Fortify::confirmsTwoFactorAuthentication() && is_null($user->two_factor_confirmed_at)) {
            $disableTwoFactorAuthentication($user);
            $user->refresh();
        }

        // 2FA management itself is not wired up in this UI — see Security.jsx.
        // canManageTwoFactor is still passed through so the page can show an
        // honest "not available yet" state instead of hiding the section.
        $twoFactorEnabled = $canManageTwoFactor && ! is_null($user->two_factor_secret);

        return Inertia::render('Settings/Security', [
            'canManageTwoFactor' => $canManageTwoFactor,
            'twoFactorEnabled' => $twoFactorEnabled,
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return back()->with('success', 'Password updated.');
    }
}
