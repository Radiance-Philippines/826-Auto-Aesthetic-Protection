<?php

namespace App\Support;

use App\Models\Review;

/**
 * The team's decisions on a customer's review, for both admins. Only an
 * approved review reaches the site (the model keeps featured and the
 * approval time in step).
 */
final class ReviewModeration
{
    public static function approve(Review $review): void
    {
        $review->update(['status' => 'approved', 'admin_note' => null]);
    }

    /**
     * @param  string|null  $note  for the team only; the customer never sees it
     */
    public static function reject(Review $review, ?string $note): void
    {
        $review->update(['status' => 'rejected', 'admin_note' => $note]);
    }

    public static function toggleFeatured(Review $review): void
    {
        $review->update(['is_featured' => ! $review->is_featured]);
    }

    public static function stars(int $rating): string
    {
        return str_repeat('★', $rating).str_repeat('☆', 5 - $rating);
    }
}
