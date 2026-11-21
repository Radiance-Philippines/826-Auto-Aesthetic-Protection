import type { Review } from '../types';
import ReviewCard from './ReviewCard';

/**
 * Reviews in a row you swipe on phones and a grid from sm up; `wrap`
 * keeps the grid on phones too (the Our work page lists them all).
 */
export default function ReviewList({ reviews, wrap = false }: { reviews: Review[]; wrap?: boolean }) {
    return (
        <ul className={wrap ? 'grid gap-4 sm:grid-cols-2 lg:grid-cols-3' : 'no-scrollbar -mx-4 flex snap-x snap-mandatory items-start gap-4 overflow-x-auto px-4 pb-1 sm:mx-0 sm:grid sm:grid-cols-2 sm:items-stretch sm:overflow-visible sm:px-0 lg:grid-cols-3'}>
            {reviews.map((review) => (
                <li key={review.id} className={wrap ? '' : 'w-[82vw] shrink-0 snap-start sm:w-auto'}>
                    <ReviewCard review={review} />
                </li>
            ))}
        </ul>
    );
}
