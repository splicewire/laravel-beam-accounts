<?php

namespace Splicewire\Beam\Accounts\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;
use Splicewire\Beam\Accounts\Data\PasswordUpdateInputData;

/**
 * The Inertia settings-security surface.
 *
 * `edit` took a `SecurityPageRequest` whose whole body was `rules(): return []` plus
 * `authorize(): $this->user() !== null` — a FormRequest standing in for auth middleware. The route
 * already runs behind the settings group's `auth` middleware, so the class asserted a condition that
 * could not be false by the time it ran; it is gone rather than replaced.
 */
class SecurityController extends Controller
{
    public function edit(Request $request): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => $this->passkeys($request),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $props['twoFactorEnabled'] = (bool) $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/security', $props);
    }

    /**
     * The user's registered passkeys, when the feature and user support them.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function passkeys($request): array
    {
        if (! Features::canManagePasskeys() || ! method_exists($request->user(), 'passkeys')) {
            return [];
        }

        return $request->user()->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn ($passkey) => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->values()
            ->all();
    }

    public function update(Request $request, PasswordUpdateInputData $input): RedirectResponse
    {
        $request->user()->update([
            'password' => $input->password,
        ]);

        return back()->with('status', 'password-updated');
    }
}
