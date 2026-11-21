import { copy } from '../lib/copy';
import { parseDate } from '../lib/dates';
import type { Review } from '../types';
import Picture from './Picture';

const text = copy.reviews;

function Star({ filled, className = 'size-4' }: { filled: boolean; className?: string }) {
    return (
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" className={`${className} ${filled ? 'fill-accent text-accent' : 'fill-none text-muted/60'}`} stroke="currentColor" strokeWidth={1.5} strokeLinejoin="round">
            <path d="m12 3.2 2.7 5.6 6.1.8-4.5 4.2 1.1 6.1L12 17l-5.4 2.9 1.1-6.1-4.5-4.2 6.1-.8z" />
        </svg>
    );
}

/** Five stars, read as "5 out of 5" */
export function Stars({ rating, className }: { rating: number; className?: string }) {
    return (
        <span role="img" aria-label={text.stars(rating)} className="inline-flex gap-0.5">
            {[1, 2, 3, 4, 5].map((value) => (
                <Star key={value} filled={value <= rating} className={className} />
            ))}
        </span>
    );
}

export { Star };

/** "2026-11-20" → "November 2026" */
const monthYear = (iso: string) =>
    parseDate(iso).toLocaleDateString('en-PH', {
        month: 'long',
        year: 'numeric',
    });

/** One approved review: the stars, the words, and who, what and where */
export default function ReviewCard({ review }: { review: Review }) {
    const byline = [review.name, review.service, review.branch, monthYear(review.date)].filter(Boolean);

    return (
        <figure className="panel flex h-full flex-col p-5 sm:p-6">
            <Stars rating={review.rating} />
            <blockquote className="mt-4 flex-1 leading-relaxed whitespace-pre-line">{review.body}</blockquote>
            {review.photo && <Picture image={review.photo} alt={text.photoAlt(review.name)} sizes="(min-width: 1024px) 30vw, 80vw" className="mt-4 aspect-[4/3] w-full rounded-control bg-surface-3 object-cover" />}
            <figcaption className="mt-5 border-t border-line pt-4 text-sm text-muted">
                <span className="font-semibold text-text">{byline[0]}</span>
                {byline.slice(1).map((part) => (
                    <span key={part}>
                        {' · '}
                        {part}
                    </span>
                ))}
            </figcaption>
        </figure>
    );
}
