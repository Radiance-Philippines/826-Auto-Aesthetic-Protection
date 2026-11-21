import { type FormEvent, useEffect, useId, useRef, useState } from 'react';

import { fileSize, proofProblem, REVIEW_PHOTO_ACCEPT, sendReview } from '../lib/bookings';
import { copy } from '../lib/copy';
import type { CustomerBooking, MyReview } from '../types';
import { buttonClass } from './Button';
import { ErrorText, TextField } from './Form';
import { ImagePlus, Trash, Warning } from './Icons';
import { Star, Stars } from './ReviewCard';

const text = copy.reviews;
const MIN = 10;
const MAX = 1000;

/** Five stars as a radio group: arrow keys move between them */
function RatingPicker({ value, onChange, error }: { value: number; onChange: (value: number) => void; error: string | null }) {
    const name = useId();
    const errorId = `${name}-error`;

    return (
        <fieldset aria-describedby={error ? errorId : undefined}>
            <legend className="font-semibold">{text.form.rating}</legend>
            <div className="mt-2 flex gap-1">
                {[1, 2, 3, 4, 5].map((star) => (
                    <label key={star} className="press relative grid size-11 cursor-pointer place-items-center rounded-control hover:bg-text/5 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-accent">
                        <input type="radio" name={name} value={star} checked={value === star} onChange={() => onChange(star)} aria-label={text.form.star(star)} className="peer sr-only" />
                        <Star filled={star <= value} className="size-8" />
                    </label>
                ))}
            </div>
            {error && <ErrorText id={errorId}>{error}</ErrorText>}
        </fieldset>
    );
}

function PhotoPicker({ file, onPick, error }: { file: File | null; onPick: (file: File | null) => void; error: string | null }) {
    const input = useRef<HTMLInputElement>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const id = useId();

    useEffect(() => {
        if (!file) {
            setPreview(null);

            return;
        }

        const url = URL.createObjectURL(file);
        setPreview(url);

        return () => URL.revokeObjectURL(url);
    }, [file]);

    return (
        <div>
            <p className="font-semibold">
                {text.form.photo} <span className="ml-1 text-sm font-normal text-muted">({copy.book.details.optional})</span>
            </p>
            <p id={`${id}-hint`} className="mt-1 text-sm text-muted">
                {text.form.photoHint}
            </p>
            <input
                ref={input}
                type="file"
                accept={REVIEW_PHOTO_ACCEPT}
                aria-label={text.form.photo}
                aria-describedby={`${id}-hint`}
                className="sr-only"
                onChange={(event) => {
                    onPick(event.target.files?.[0] ?? null);
                    event.target.value = '';
                }}
            />
            {file && preview ? (
                <div className="mt-3 flex items-center gap-4">
                    <img src={preview} alt={text.form.previewAlt} className="size-24 rounded-control bg-surface-3 object-cover" />
                    <div className="min-w-0 space-y-2">
                        <p className="truncate text-sm text-muted">
                            {file.name} · {fileSize(file.size)}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <button type="button" onClick={() => input.current?.click()} className={buttonClass('outline', 'min-h-11! px-4! text-sm!')}>
                                <ImagePlus className="size-4" /> {text.form.change}
                            </button>
                            <button type="button" onClick={() => onPick(null)} className={buttonClass('outline', 'min-h-11! px-4! text-sm!')}>
                                <Trash className="size-4" /> {text.form.remove}
                            </button>
                        </div>
                    </div>
                </div>
            ) : (
                <button type="button" onClick={() => input.current?.click()} className={buttonClass('outline', 'mt-3 min-h-11! px-4! text-sm!')}>
                    <ImagePlus className="size-4" /> {text.form.photo}
                </button>
            )}
            {error && <ErrorText>{error}</ErrorText>}
        </div>
    );
}

const statusOf = {
    pending: text.mine.pending,
    approved: text.mine.approved,
    rejected: text.mine.rejected,
} as const;

/** The customer's own review, with where it stands */
function MyReviewCard({ review }: { review: MyReview }) {
    return (
        <section aria-labelledby="my-review-title" className="panel p-5 sm:p-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 id="my-review-title" className="text-lg font-bold">
                    {text.mine.title}
                </h2>
                <span className="rounded-full bg-surface-3 px-3 py-1 text-[0.8125rem] font-semibold">{statusOf[review.status]}</span>
            </div>
            <div className="mt-3">
                <Stars rating={review.rating} />
            </div>
            <p className="mt-3 leading-relaxed whitespace-pre-line">{review.body}</p>
            {review.photo && <img src={review.photo} alt="" loading="lazy" decoding="async" className="mt-4 max-h-64 rounded-control bg-surface-3 object-cover" />}
        </section>
    );
}

type Errors = { rating?: string; body?: string; photo?: string; form?: string };

/**
 * "How did we do?" for a finished booking, or the review it already has.
 * Renders nothing for a booking that can't be reviewed and has none.
 */
export default function ReviewSection({ booking, onSent, onMessage }: { booking: CustomerBooking; onSent: (review: MyReview) => void; onMessage: (message: string) => void }) {
    const [rating, setRating] = useState(0);
    const [body, setBody] = useState('');
    const [photo, setPhoto] = useState<File | null>(null);
    const [errors, setErrors] = useState<Errors>({});
    const [working, setWorking] = useState(false);

    if (booking.review) {
        return <MyReviewCard review={booking.review} />;
    }

    if (!booking.canReview) {
        return null;
    }

    const pickPhoto = (file: File | null) => {
        const problem = file ? proofProblem(file) : null;
        setErrors((current) => ({
            ...current,
            photo: problem === 'type' ? text.form.photoType : problem === 'size' ? text.form.photoSize : undefined,
        }));
        setPhoto(problem ? null : file);
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        const found: Errors = {
            rating: rating < 1 ? text.form.noRating : undefined,
            body: body.trim().length < MIN ? text.form.tooShort : undefined,
        };
        setErrors(found);

        if (found.rating || found.body) {
            return;
        }

        setWorking(true);
        const result = await sendReview(booking.id, rating, body, photo);
        setWorking(false);

        if (result.kind === 'ok') {
            if (result.data.demo) {
                onMessage(text.form.demo);
            } else if (result.data.review) {
                onSent(result.data.review);
            }

            return;
        }

        if (result.kind === 'invalid') {
            setErrors({
                rating: result.errors.rating?.[0],
                body: result.errors.body?.[0],
                photo: result.errors.photo?.[0],
                form: result.errors.rating || result.errors.body || result.errors.photo ? undefined : (result.message ?? copy.book.errors.failed),
            });
        } else if (result.kind === 'conflict') {
            setErrors({ form: result.message ?? copy.book.errors.failed });
        } else {
            setErrors({
                form: result.kind === 'tooMany' ? copy.book.errors.tooMany : result.kind === 'stale' ? copy.book.errors.stale : copy.book.errors.failed,
            });
        }
    };

    return (
        <section aria-labelledby="review-title" className="panel p-5 sm:p-6">
            <h2 id="review-title" className="text-lg font-bold">
                {text.form.title}
            </h2>
            <p className="mt-1 text-muted">{text.form.body}</p>
            <form onSubmit={submit} noValidate className="mt-5 space-y-5">
                <RatingPicker
                    value={rating}
                    onChange={(value) => {
                        setRating(value);
                        setErrors((current) => ({ ...current, rating: undefined }));
                    }}
                    error={errors.rating ?? null}
                />
                <div>
                    <TextField
                        label={text.form.text}
                        hint={text.form.hint}
                        value={body}
                        onChange={(value) => {
                            setBody(value);
                            setErrors((current) => ({ ...current, body: undefined }));
                        }}
                        multiline
                        rows={5}
                        maxLength={MAX}
                        error={errors.body}
                    />
                    <p className="mt-1.5 text-right text-sm text-muted tabular" aria-hidden="true">
                        {text.form.count(body.length, MAX)}
                    </p>
                </div>
                <PhotoPicker file={photo} onPick={pickPhoto} error={errors.photo ?? null} />
                {errors.form && (
                    <p role="alert" className="flex items-start gap-2 font-medium text-danger">
                        <Warning className="mt-0.5 size-4 shrink-0" /> {errors.form}
                    </p>
                )}
                <button type="submit" disabled={working} className={buttonClass('accent')}>
                    {working ? text.form.sending : text.form.submit}
                </button>
            </form>
        </section>
    );
}
