<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('token', 64)->unique();
            $table->string('scope');
            $table->string('role');
            $table->jsonb('params')->nullable();
            $table->string('landing_url');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->string('label')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_tokens');
    }
};
