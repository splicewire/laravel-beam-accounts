<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pat-permission-scope — archive instead of hard-delete. Adds `archived_at` so revoking a
 * token retains its record (name, scope, created/last-used) for audit rather than erasing it,
 * which is the SOC-2-friendlier credential-lifecycle story. Archiving also neutralizes the
 * stored hash at the application layer so an archived token can never authenticate again; this
 * column is the audit marker + list filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
