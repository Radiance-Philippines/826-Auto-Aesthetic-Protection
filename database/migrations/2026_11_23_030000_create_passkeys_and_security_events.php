<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Passkeys for the admin team (the table spatie/laravel-passkeys expects,
     * plus an index on the credential id), a small security audit trail, and
     * the Settings → Security switch for passkey sign-in (on by default).
     */
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('authenticatable_id')->constrained('users')->cascadeOnDelete();
            $table->text('name');
            $table->text('credential_id');
            $table->json('data');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('credential_id', 'passkeys_credential_id_index');
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Who did it, when it was someone else (a super admin removing a lost device)
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 40);
            $table->string('note')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'id']);
        });

        Schema::table('security_settings', function (Blueprint $table) {
            $table->boolean('allow_passkeys')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('security_settings', function (Blueprint $table) {
            $table->dropColumn('allow_passkeys');
        });

        Schema::dropIfExists('security_events');
        Schema::dropIfExists('passkeys');
    }
};
