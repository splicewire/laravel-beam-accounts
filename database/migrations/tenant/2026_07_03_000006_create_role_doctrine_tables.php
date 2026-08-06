<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role doctrine (dealer-network B1): a Corporate/publisher-authored `RoleTemplate`
 * (e.g. "Service Manager") and the `RoleObligation`s it imposes. An obligation reuses
 * the engine's stable `requirement_key`, so a role simply *bundles* existing
 * requirements — it does not introduce a parallel requirement vocabulary.
 *
 * The public / private / self-made source-of-law ladder is encoded in each
 * obligation's `precedence_tier` + `source` (the same convention the profile bundle
 * uses: public law = tier 4, private/OEM = tier 5, corporate self-made policy = its
 * own tier + `source`). We deliberately do NOT fork the engine `RuleLayer` enum with a
 * `Corporate` case — corporate policy is tagged, not enumerated, keeping the engine
 * domain-agnostic.
 *
 * Both tables are UUID-keyed with stable string business refs (`template_id` /
 * `obligation_id`) so they ride the System Tenant → ScaffoldPack → Tenant Sync path
 * like the rest of the corpus; an obligation references its template by the stable
 * `role_template_id`, never a per-tenant uuid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('template_id')->unique();   // stable business ref, e.g. role:service-manager
            $table->string('name');                     // "Service Manager"
            $table->string('sub_vertical')->default('dealer-network-heavy-equipment')->index();

            $table->timestamps();
        });

        Schema::create('role_obligations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('obligation_id')->unique(); // stable business ref
            $table->string('role_template_id')->index(); // → role_templates.template_id
            $table->string('requirement_key')->index(); // reuses Rule.requirement_key

            // Source-of-law ladder — tagged, never a forked enum case.
            $table->unsignedSmallInteger('precedence_tier');
            $table->string('source');

            $table->timestamps();

            $table->index(['role_template_id', 'requirement_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_obligations');
        Schema::dropIfExists('role_templates');
    }
};
