<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each branch has its own Facebook Page: its Messenger chats land in the
     * admin inbox. The Page token is encrypted (see Branch's casts).
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('facebook_page_id', 50)->nullable()->unique();
            $table->string('facebook_page_name')->nullable();
            $table->text('facebook_page_token')->nullable();
            $table->timestamp('facebook_connected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique(['facebook_page_id']);
            $table->dropColumn(['facebook_page_id', 'facebook_page_name', 'facebook_page_token', 'facebook_connected_at']);
        });
    }
};
