<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\ConvergentTable;

/**
 * TENANT-ONLY, single create, no ALTERs to squash — the ownership pivot binding a user to
 * tenant-resident records (the `userable` uuid morph pair): the pivot rows are the tenant's own
 * data and participate in its churn, so they live inside the schema with the records they bind.
 * `user_id` is deliberately unconstrained (`foreignUuid` with no `->constrained()`) — the
 * principal it points at lives outside this schema, and a tenant-schema table cannot enforce an
 * FK across the isolation wall.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        ConvergentTable::named('userables')
            ->define(function (Blueprint $table) {
                $table->foreignUuid('user_id');
                $table->uuidMorphs('userable');
            })
            ->assert();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('userables');
    }
};
