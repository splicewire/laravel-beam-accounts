<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the legacy "userish" System Account row in place.
 *
 * The row is the app's System Account — the non-human owner-of-record for
 * machine-generated fragments/anchors (see CONTEXT.md → "System Account").
 * Ownership is by the `user_id` FK, so renaming the email/name in place keeps
 * every owned record pointing at the same principal. Guarded so it no-ops where
 * the legacy row is absent, and skips (rather than colliding on the unique email)
 * if a `system@` row already exists.
 */
return new class extends Migration
{
    private string $old = 'userish@app.splicewire.com';

    private string $new = 'system@app.splicewire.com';

    public function up(): void
    {
        $this->rename($this->old, $this->new, 'Userish', 'System');
    }

    public function down(): void
    {
        $this->rename($this->new, $this->old, 'System', 'Userish');
    }

    private function rename(string $from, string $to, string $fromName, string $toName): void
    {
        if (DB::table('users')->where('email', $to)->exists()) {
            return;
        }

        DB::table('users')
            ->where('email', $from)
            ->update([
                'email' => $to,
                'name' => DB::raw("CASE WHEN name = '{$fromName}' THEN '{$toName}' ELSE name END"),
            ]);
    }
};
