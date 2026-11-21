import { useState } from 'react';

import { copy } from '../lib/copy';
import type { VideoItem } from '../types';
import { ArrowRight } from './Icons';

const text = copy.gallery;

/**
 * A YouTube video: its thumbnail until tapped, then the player (privacy-
 * enhanced embed). Nothing from YouTube loads until then, except the picture.
 */
export default function VideoCard({ video }: { video: VideoItem }) {
    const [playing, setPlaying] = useState(false);

    return (
        <figure>
            <div className="relative aspect-video overflow-hidden rounded-card bg-surface-3">
                {playing ? (
                    <iframe
                        src={`https://www.youtube-nocookie.com/embed/${video.youtubeId}?autoplay=1&rel=0`}
                        title={text.playing(video.title)}
                        allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
                        allowFullScreen
                        className="absolute inset-0 size-full border-0"
                    />
                ) : (
                    <button type="button" onClick={() => setPlaying(true)} aria-label={text.play(video.title)} className="press group absolute inset-0 block size-full">
                        <img src={video.thumbnail} alt="" width={480} height={360} loading="lazy" decoding="async" className="size-full object-cover" />
                        <span aria-hidden="true" className="absolute inset-0 grid place-items-center bg-black/25 group-hover:bg-black/10">
                            <span className="grid size-16 place-items-center rounded-full bg-accent text-on-accent shadow-lg">
                                <svg viewBox="0 0 24 24" className="ml-1 size-7" fill="currentColor">
                                    <path d="M8 5v14l11-7z" />
                                </svg>
                            </span>
                        </span>
                    </button>
                )}
            </div>
            <figcaption className="mt-3">
                <p className="font-semibold">{video.title}</p>
                {video.caption && <p className="mt-1 text-sm text-muted">{video.caption}</p>}
                <a href={video.url} target="_blank" rel="noopener noreferrer" className="link mt-2 inline-flex min-h-11 items-center gap-1.5 text-sm">
                    {text.onYouTube} <ArrowRight className="size-3.5" />
                </a>
            </figcaption>
        </figure>
    );
}
