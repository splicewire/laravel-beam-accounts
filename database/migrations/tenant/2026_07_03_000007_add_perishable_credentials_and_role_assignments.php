<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perishable credentials + role instances (dealer-network B2).
 *
 * `compliance_evidence` gains a nullable validity window (`valid_from`/`valid_until`)
 * plus `holder_name` / `holder_scope_ref`. The columns are nullable and default-free,
 * so existing merchant COA rows are untouched — they carry no window and are always in
 * force. A credential whose window has lapsed is dropped by the corpus source, so the
 * claim it backed falls through the existing SubstantiationCheck "no bound evidence →
 * Unknown" path into an honest gap (no engine change).
 *
 * `holder_scope_ref` is deliberately shape-neutral — the edge subject the credential is
 * scoped to (a dealer instance = a location), never a dealer-specific word.
 *
 * `role_assignments` binds a granted {@see RoleTemplate} (by stable `role_template_id`)
 * to a `TenantUser` — instance data, per-tenant, NOT synced corpus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_evidence', function (Blueprint $table) {
            $table->timestamp('valid_from')->nullable()->after('provenance_tier');
            $table->timestamp('valid_until')->nullable()->after('valid_from');
            $table->string('holder_name')->nullable()->after('valid_until');
            $table->string('holder_scope_ref')->nullable()->index()->after('holder_name');
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('assignment_id')->unique();   // stable business ref
            $table->string('role_template_id')->index(); // → role_templates.template_id
            $table->uuid('tenant_user_id')->index();      // → tenant_users.id
            $table->string('holder_scope_ref')->nullable()->index(); // edge subject (e.g. location)

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');

        Schema::table('compliance_evidence', function (Blueprint $table) {
            $table->dropColumn(['valid_from', 'valid_until', 'holder_name', 'holder_scope_ref']);
        });
    }
};
