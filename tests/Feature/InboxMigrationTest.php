<?php

namespace Tests\Feature;

use App\Models\Branch;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The inbox migrations only add: existing branches keep everything they had.
 */
class InboxMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_existing_branches_keep_their_data_and_only_gain_empty_facebook_columns(): void
    {
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_11_23_010001_create_meta_settings_and_inbox_tables.php'])->assertSuccessful();
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_11_23_010000_add_facebook_page_to_branches.php'])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('branches', 'facebook_page_id'));
        $this->assertFalse(Schema::hasTable('conversations'));

        $now = now()->toDateTimeString();
        $id = DB::table('branches')->insertGetId(['name' => 'Cainta', 'slug' => 'cainta', 'status' => 'open', 'daily_cap' => 3, 'messenger_url' => 'https://m.me/826', 'created_at' => $now, 'updated_at' => $now]);

        $this->artisan('migrate')->assertSuccessful();

        $branch = Branch::findOrFail($id);
        $this->assertSame(['Cainta', 'cainta', 3, 'https://m.me/826'], [$branch->name, $branch->slug, $branch->daily_cap, $branch->messenger_url]);
        $this->assertSame([null, null, null, null], [$branch->facebook_page_id, $branch->facebook_page_name, $branch->facebook_page_token, $branch->facebook_connected_at]);
        $this->assertTrue(Schema::hasTable('meta_settings') && Schema::hasTable('conversations') && Schema::hasTable('conversation_messages'));
        $this->assertSame(0, DB::table('conversations')->count());
    }
}
