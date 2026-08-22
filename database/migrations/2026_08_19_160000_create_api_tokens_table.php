<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tokens the CRM presents when it calls this app.
     *
     * Only a hash is stored. The plaintext is shown once, at the moment it is
     * generated, and then this app can never show it again — which is the point:
     * a token this app could read back is a token anyone with database access
     * could read back too.
     *
     * last_used_at earns its place. "Why does the CRM say HRIS is unavailable"
     * is answered instantly by a token that has never been used, and that is the
     * question this table exists to settle.
     */
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // SHA-256 of the plaintext. Indexed because every API request looks
            // a token up by it.
            $table->string('token_hash', 64)->unique();

            // Enough of the token to recognise it in a list, and no more.
            $table->string('token_hint', 20);

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();

            $table->timestamps();

            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
