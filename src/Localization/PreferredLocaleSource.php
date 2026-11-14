<?php

namespace Splicewire\Beam\Accounts\Localization;

use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Splicewire\Beam\Localization\Contracts\PersonLocaleSource;
use Splicewire\Beam\Localization\DeliveryRecipient;

/**
 * Beam's person slot, contributed by beam-accounts (localization 36; ruling 03 §2 source 2 and §3 delivery source 1).
 *
 * - **A request:** the signed-in person's saved, supported language (read from the central identity), or null. The
 *   reduced resolver never asks this slot, so an unavailable person is never consulted.
 * - **A delivery:** the recipient's. That's its own `preferredLocale()` when the host composed one, else a model's
 *   central value, else, for an invitee addressed only by email, the central account with that email, if any.
 *
 * It answers null wherever no person or no saved choice exists, and the resolver moves on to the defaults.
 */
final class PreferredLocaleSource implements PersonLocaleSource
{
    public function locale(?Request $request, mixed $recipient = null): ?string
    {
        if ($request !== null) {
            $user = $request->user();

            return $user instanceof Model ? PreferredLocale::of($user) : null;
        }

        $notifiable = DeliveryRecipient::notifiableOf($recipient);
        if ($notifiable instanceof HasLocalePreference) {
            $preferred = $notifiable->preferredLocale();

            return is_string($preferred) && $preferred !== '' ? $preferred : null;
        }
        if ($notifiable instanceof Model) {
            return PreferredLocale::of($notifiable);
        }
        if ($notifiable instanceof AnonymousNotifiable) {
            $route = $notifiable->routes['mail'] ?? null;
            // A mail route is an address, a list of addresses, or address => name.
            $email = is_array($route) ? (array_is_list($route) ? ($route[0] ?? null) : array_key_first($route)) : $route;
            if (is_string($email) && $email !== '') {
                return PreferredLocale::of(PreferredLocale::centralByEmail($email));
            }
        }

        return null;
    }
}
