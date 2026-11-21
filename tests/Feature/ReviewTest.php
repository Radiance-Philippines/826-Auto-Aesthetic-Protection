<?php

namespace Tests\Feature;

use App\Filament\Resources\Reviews\Pages\EditReview;
use App\Filament\Resources\Reviews\Pages\ListReviews;
use App\Filament\Resources\Reviews\ReviewResource;
use App\Mail\CustomerBookingUpdate;
use App\Mail\TeamBookingAlert;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\MailSetting;
use App\Models\Review;
use App\Models\User;
use App\Support\ImagePipeline;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Moderated reviews: only a finished booking's signed-in customer writes
 * one, the team approves it, and only approved ones reach the site.
 */
class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private Guest $guest;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Mail::fake();

        $this->guest = Guest::factory()->create(['name' => 'Maria Santos']);
        $this->booking = Booking::factory()->status('exited')->create(['guest_id' => $this->guest->id, 'name' => 'Maria Santos']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(array $data = [], ?Guest $as = null, ?Booking $booking = null)
    {
        return $this->actingAs($as ?? $this->guest, 'guest')
            ->postJson('/api/my-bookings/'.($booking ?? $this->booking)->id.'/review', ['rating' => 5, 'body' => 'Spotless work, my car looks new.', ...$data]);
    }

    public function test_the_migration_creates_the_table_with_one_review_per_booking(): void
    {
        $this->assertTrue(Schema::hasColumns('reviews', ['guest_id', 'booking_id', 'branch_id', 'rating', 'body', 'photo', 'status', 'admin_note', 'approved_at', 'display_name', 'is_featured']));

        Review::factory()->create(['booking_id' => $this->booking->id, 'guest_id' => $this->guest->id]);
        $this->expectException(UniqueConstraintViolationException::class);
        Review::factory()->create(['booking_id' => $this->booking->id, 'guest_id' => $this->guest->id]);
    }

    public function test_the_owner_of_an_exited_booking_sends_a_pending_review(): void
    {
        $this->send()->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('review.status', 'pending')
            ->assertJsonPath('review.rating', 5)
            ->assertJsonPath('review.photo', null);

        $review = Review::sole();
        $this->assertSame('pending', $review->status);
        $this->assertSame('Maria S.', $review->display_name);
        $this->assertSame($this->booking->branch_id, $review->branch_id);
        $this->assertNull($review->approved_at);
    }

    public function test_a_booking_can_only_be_reviewed_once(): void
    {
        $this->send()->assertCreated();
        $this->send()->assertStatus(409);
        $this->assertSame(1, Review::count());
    }

    public function test_only_an_exited_booking_can_be_reviewed(): void
    {
        $open = Booking::factory()->status('in_progress')->create(['guest_id' => $this->guest->id]);

        $this->send(booking: $open)->assertStatus(409);
        $this->assertSame(0, Review::count());
    }

    public function test_nobody_else_can_review_it(): void
    {
        $this->send(as: Guest::factory()->create())->assertNotFound();
        auth('guest')->logout();
        $this->postJson("/api/my-bookings/{$this->booking->id}/review", ['rating' => 5, 'body' => 'Spotless work, my car looks new.'])->assertUnauthorized();
        // The private link opens the booking, but it isn't the customer's sign-in
        $this->postJson("/api/my-bookings/{$this->booking->id}/review?key={$this->booking->access_key}", ['rating' => 5, 'body' => 'Spotless work, my car looks new.'])->assertUnauthorized();
        $this->assertSame(0, Review::count());
    }

    public function test_the_rating_and_the_words_are_checked(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->send(['rating' => 0])->assertUnprocessable()->assertJsonValidationErrors('rating');
        $this->send(['rating' => 6])->assertUnprocessable()->assertJsonValidationErrors('rating');
        $this->send(['body' => 'Too short'])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->send(['body' => str_repeat('a', 1001)])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->send(['photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->send(['photo' => UploadedFile::fake()->image('big.jpg')->size(9000)])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->assertSame(0, Review::count());
    }

    public function test_the_photo_goes_through_the_pipeline_and_leaves_with_the_review(): void
    {
        $this->send(['photo' => UploadedFile::fake()->image('car.jpg', 1600, 1000)])->assertCreated();

        $review = Review::sole();
        $this->assertStringEndsWith('.webp', $review->photo);
        $this->assertStringStartsWith('reviews/', $review->photo);
        Storage::disk('public')->assertExists($review->photo);
        Storage::disk('public')->assertExists(ImagePipeline::renditionPath($review->photo, 'thumb'));

        $master = $review->photo;
        $review->delete();

        Storage::disk('public')->assertMissing($master);
        Storage::disk('public')->assertMissing(ImagePipeline::renditionPath($master, 'thumb'));
    }

    public function test_a_demo_validates_and_saves_nothing(): void
    {
        config(['app.demo' => true]);

        $this->send(['photo' => UploadedFile::fake()->image('car.jpg')])->assertOk()->assertJson(['ok' => true, 'demo' => true]);
        $this->send(['body' => 'short'])->assertUnprocessable();
        $this->assertSame(0, Review::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_review_is_throttled(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->send(['body' => 'short'])->assertUnprocessable();
        }

        $this->send(['body' => 'short'])->assertTooManyRequests();
    }

    public function test_the_booking_tells_the_customer_whether_they_can_review_and_how_it_went(): void
    {
        $this->actingAs($this->guest, 'guest')->getJson("/api/my-bookings/{$this->booking->id}")
            ->assertOk()->assertJsonPath('booking.canReview', true)->assertJsonPath('booking.review', null);

        $this->send()->assertCreated();

        $this->getJson("/api/my-bookings/{$this->booking->id}")
            ->assertJsonPath('booking.canReview', false)
            ->assertJsonPath('booking.review.status', 'pending')
            ->assertJsonPath('booking.review.body', 'Spotless work, my car looks new.');

        $this->getJson('/api/my-bookings')->assertJsonPath('bookings.0.canReview', false);

        // An open booking can't be reviewed yet, and the private link alone can't either
        $open = Booking::factory()->status('confirmed')->create(['guest_id' => $this->guest->id]);
        $this->getJson("/api/my-bookings/{$open->id}")->assertJsonPath('booking.canReview', false);
        auth('guest')->logout();
        $this->getJson("/api/my-bookings/{$this->booking->id}?key={$this->booking->access_key}")->assertJsonPath('booking.canReview', false);
    }

    public function test_the_team_is_emailed_and_the_customer_is_not(): void
    {
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'secret']);

        $this->send()->assertCreated();

        Mail::assertSent(TeamBookingAlert::class, fn (TeamBookingAlert $mail) => $mail->kind === 'review'
            && str_contains($mail->envelope()->subject, 'New review waiting for approval')
            && str_contains($mail->render(), ReviewResource::getUrl('index', panel: 'admin')));
        Mail::assertNotSent(CustomerBookingUpdate::class);
    }

    public function test_only_approved_reviews_reach_the_site_featured_first_then_newest(): void
    {
        BookingItem::create(['booking_id' => $this->booking->id, 'service_name' => 'Graphene coating', 'sort_order' => 1]);
        $old = Review::factory()->approved()->create(['booking_id' => $this->booking->id, 'guest_id' => $this->guest->id, 'body' => 'Old one', 'approved_at' => now()->subDays(5)]);
        $new = Review::factory()->approved()->create(['body' => 'New one', 'approved_at' => now()->subDay()]);
        $featured = Review::factory()->approved()->create(['body' => 'Featured one', 'is_featured' => true, 'approved_at' => now()->subDays(9)]);
        Review::factory()->create(['body' => 'Pending one']);
        Review::factory()->rejected()->create(['body' => 'Rejected one']);

        $response = $this->withoutVite()->get('/');
        $reviews = collect($response->viewData('app')['reviews']);

        $this->assertSame([$featured->id, $new->id, $old->id], $reviews->pluck('id')->all());
        $this->assertSame('Graphene coating', $reviews->firstWhere('id', $old->id)['service']);
        $this->assertSame($this->booking->branch->name, $reviews->firstWhere('id', $old->id)['branch']);
        $this->assertSame(['id', 'name', 'rating', 'body', 'photo', 'service', 'branch', 'date'], array_keys($reviews->first()));
        $this->assertStringNotContainsString('Pending one', $response->getContent());
        $this->assertStringNotContainsString('Rejected one', $response->getContent());
    }

    public function test_the_site_carries_at_most_24_reviews(): void
    {
        Review::factory()->approved()->count(26)->create();

        $this->assertCount(24, $this->withoutVite()->get('/gallery')->viewData('app')['reviews']);
    }

    public function test_the_team_approves_rejects_and_features_in_the_admin(): void
    {
        $review = Review::factory()->create();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ListReviews::class)
            ->assertCanSeeTableRecords([$review])
            ->callTableAction('approve', $review);

        $review->refresh();
        $this->assertSame('approved', $review->status);
        $this->assertNotNull($review->approved_at);

        Livewire::test(ListReviews::class, ['activeTab' => 'approved'])
            ->callTableAction('feature', $review);
        $this->assertTrue($review->refresh()->is_featured);

        Livewire::test(ListReviews::class, ['activeTab' => 'approved'])
            ->callTableAction('reject', $review, ['admin_note' => 'Not about us']);

        $review->refresh();
        $this->assertSame('rejected', $review->status);
        $this->assertSame('Not about us', $review->admin_note);
        $this->assertNull($review->approved_at);
        $this->assertFalse($review->is_featured);
    }

    public function test_the_tabs_and_the_badge_count_pending_reviews(): void
    {
        $pending = Review::factory()->create();
        $approved = Review::factory()->approved()->create();
        $rejected = Review::factory()->rejected()->create();
        $this->actingAs(User::factory()->owner()->create());

        $this->assertSame('1', ReviewResource::getNavigationBadge());

        Livewire::test(ListReviews::class)
            ->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$approved, $rejected]);
        Livewire::test(ListReviews::class, ['activeTab' => 'approved'])
            ->assertCanSeeTableRecords([$approved])->assertCanNotSeeTableRecords([$pending]);
        Livewire::test(ListReviews::class, ['activeTab' => 'rejected'])
            ->assertCanSeeTableRecords([$rejected])->assertCanNotSeeTableRecords([$pending]);
        Livewire::test(ListReviews::class, ['activeTab' => 'all'])
            ->assertCanSeeTableRecords([$pending, $approved, $rejected]);
    }

    public function test_branch_staff_see_only_their_branch_s_reviews_and_only_owners_delete(): void
    {
        $cainta = Branch::factory()->create();
        $sanMateo = Branch::factory()->create();
        $mine = Review::factory()->create(['booking_id' => Booking::factory()->status('exited')->create(['branch_id' => $cainta->id, 'guest_id' => $this->guest->id])->id, 'guest_id' => $this->guest->id]);
        $theirs = Review::factory()->create(['booking_id' => Booking::factory()->status('exited')->create(['branch_id' => $sanMateo->id, 'guest_id' => $this->guest->id])->id, 'guest_id' => $this->guest->id]);

        $staff = User::factory()->create();
        $staff->branches()->attach($cainta);
        $this->actingAs($staff);

        Livewire::test(ListReviews::class, ['activeTab' => 'all'])
            ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs]);
        $this->assertSame('1', ReviewResource::getNavigationBadge());
        $this->get(ReviewResource::getUrl('view', ['record' => $theirs], panel: 'admin'))->assertNotFound();

        $this->assertFalse($staff->can('delete', $mine));
        $this->assertTrue($staff->can('update', $mine));
        $this->assertTrue(User::factory()->owner()->create()->can('delete', $mine));
    }

    public function test_the_edit_page_fixes_the_name_and_the_words(): void
    {
        $review = Review::factory()->create();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(EditReview::class, ['record' => $review->getKey()])
            ->fillForm(['display_name' => 'Maria from Cainta', 'body' => 'Fixed a typo in this review.', 'status' => 'approved'])
            ->call('save')
            ->assertHasNoFormErrors();

        $review->refresh();
        $this->assertSame('Maria from Cainta', $review->display_name);
        $this->assertSame('approved', $review->status);
        $this->assertNotNull($review->approved_at);
    }
}
