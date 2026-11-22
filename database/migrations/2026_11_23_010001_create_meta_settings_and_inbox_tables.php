<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The admin inbox: customers' Messenger chats, one set per branch Page.
     * Messages arrive by webhook, so each is stored once by Meta's message ID.
     */
    public function up(): void
    {
        // A single row, edited by the owner in /admin → Meta. The app secret is encrypted.
        Schema::create('meta_settings', function (Blueprint $table) {
            $table->id();
            $table->string('app_id', 50)->nullable();
            $table->text('app_secret')->nullable();
            // Meta sends it back when the webhook is set up (encrypted; shown to the owner once)
            $table->text('webhook_verify_token')->nullable();
            $table->timestamp('verify_token_shown_at')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20)->default('messenger');
            // The customer's page-scoped ID (PSID): who replies are sent to
            $table->string('external_id', 100);
            $table->string('customer_name')->nullable();
            $table->text('avatar')->nullable();
            // The linked customer record
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('last_message_preview')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamp('last_inbound_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            // open or done
            $table->string('status', 10)->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            // Demo data shown while Meta isn't set up (never sent anywhere)
            $table->boolean('is_sample')->default(false);
            $table->timestamps();

            $table->unique(['branch_id', 'channel', 'external_id']);
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            // in (from the customer) or out (from the shop)
            $table->string('direction', 3);
            $table->string('external_id', 150)->nullable()->unique();
            $table->text('body')->nullable();
            // [{type, url, name}], links only
            $table->json('attachments')->nullable();
            // Who answered outside this site (e.g. Meta Business Suite)
            $table->string('sender_name')->nullable();
            // The team member who replied from this site
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->index();
            $table->boolean('failed')->default(false);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('meta_settings');
    }
};
