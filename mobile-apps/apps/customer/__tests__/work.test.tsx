import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { createClient, monthYear } from '@826/core';
import { createApi } from '@/api/endpoints';
import { createMockFetch } from '@/api/mock';
import type { Showcase } from '@/api';
import { hasShowcase, PhotoGrid, ReviewCard, VideoCard, WorkPreview } from '@/features/Work';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockShowcase = jest.fn();

jest.mock('expo-router', () => ({ useRouter: () => ({ push: jest.fn(), back: jest.fn(), canGoBack: () => true, replace: jest.fn() }), useFocusEffect: (effect: () => void) => require('react').useEffect(effect, []) }));
jest.mock('@/api', () => ({ api: { showcase: (...args: unknown[]) => mockShowcase(...args) }, problemText: () => 'Problem' }));
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, bottom: 0, left: 0, right: 0 }) }));

async function render(element: ReactElement): Promise<string> {
    let renderer!: ReactTestRenderer;
    await act(async () => {
        renderer = create(element);
    });

    // Screen's ScrollView holds a RefreshControl element that points back at its own fiber
    return JSON.stringify(renderer.toJSON(), (key, value) => (key === 'refreshControl' ? undefined : value));
}

const empty: Showcase = { videos: [], photos: [], reviews: [] };
const video = { id: 1, title: 'Coating day', caption: null, youtubeId: 'aaaaaaaaaaa', thumbnail: 'https://img.youtube.com/vi/aaaaaaaaaaa/hqdefault.jpg', url: 'https://www.youtube.com/watch?v=aaaaaaaaaaa' };
const image = { src: 'https://x.test/a.webp', srcset: null, thumb: 'https://x.test/a-thumb.webp' };
const photo = { id: 1, alt: '826: coating', category: 'coating', categoryLabel: 'Coating', image };
const review = { id: 1, name: 'Carlo M.', rating: 5, body: 'Great work', photo: null, service: 'Ceramic coating', branch: 'Cainta', date: '2026-11-02' };

describe('showcase endpoint', () => {
    it('is public: no token is sent, and the shape is the contract', async () => {
        const fetchImpl = jest.fn(createMockFetch());
        const result = await createApi(createClient({ baseUrl: 'https://x.test', getToken: () => null, fetchImpl: fetchImpl as unknown as typeof fetch })).showcase();
        expect(result.kind).toBe('ok');
        expect(String(fetchImpl.mock.calls[0][0])).toBe('https://x.test/api/app/showcase');

        if (result.kind !== 'ok') return;

        expect(Object.keys(result.data)).toEqual(['videos', 'photos', 'reviews']);
        expect(Object.keys(result.data.videos[0])).toEqual(['id', 'title', 'caption', 'youtubeId', 'thumbnail', 'url']);
        expect(Object.keys(result.data.photos[0])).toEqual(['id', 'alt', 'category', 'categoryLabel', 'image']);
        expect(Object.keys(result.data.reviews[0])).toEqual(['id', 'name', 'rating', 'body', 'photo', 'service', 'branch', 'date']);
    });
});

describe('hasShowcase', () => {
    it('is false for nothing and true when any one list has something', () => {
        expect(hasShowcase(null)).toBe(false);
        expect(hasShowcase(empty)).toBe(false);
        expect(hasShowcase({ ...empty, videos: [video] })).toBe(true);
        expect(hasShowcase({ ...empty, photos: [photo] })).toBe(true);
        expect(hasShowcase({ ...empty, reviews: [review] })).toBe(true);
    });

    it('formats the review month and year', () => {
        expect(monthYear('2026-11-02')).toBe('November 2026');
    });
});

describe('Our work parts', () => {
    it('VideoCard names the video for a screen reader', async () => {
        const tree = await render(<VideoCard video={video} />);
        expect(tree).toContain('Watch video: Coating day');
    });

    it('ReviewCard carries the stars, quote, name, service, branch and month', async () => {
        const tree = await render(<ReviewCard review={review} />);
        for (const text of ['Great work', 'Carlo M.', 'Ceramic coating · Cainta · November 2026', '5 out of 5']) expect(tree).toContain(text);
    });

    it('PhotoGrid gives every photo a button label', async () => {
        expect(await render(<PhotoGrid photos={[photo]} />)).toContain('Open photo: 826: coating');
    });
});

describe('Our work screen', () => {
    const WorkScreen = require('../app/work').default;

    it('shows the sections that have content', async () => {
        mockShowcase.mockResolvedValue({ kind: 'ok', data: { videos: [video], photos: [photo], reviews: [review] } });
        const tree = await render(<WorkScreen />);
        for (const text of ['Videos', 'Photos', 'Reviews', 'Coating day', 'Great work']) expect(tree).toContain(text);
    });

    it('leaves out a section with nothing in it', async () => {
        mockShowcase.mockResolvedValue({ kind: 'ok', data: { ...empty, reviews: [review] } });
        const tree = await render(<WorkScreen />);
        expect(tree).toContain('Reviews');
        expect(tree).not.toContain('Videos');
        expect(tree).not.toContain('Photos');
    });

    it('shows an empty state when the admin has added nothing', async () => {
        mockShowcase.mockResolvedValue({ kind: 'ok', data: empty });
        expect(await render(<WorkScreen />)).toContain('Nothing here yet');
    });

    it('shows a retry when the load fails', async () => {
        mockShowcase.mockResolvedValue({ kind: 'offline' });
        expect(await render(<WorkScreen />)).toContain('Try again');
    });
});

describe('Home Our work row', () => {
    const row = (data: Showcase | null, loading = false) => render(<WorkPreview work={{ data, loading }} onSeeAll={jest.fn()} />);

    it('is left out when there is nothing, or the load failed', async () => {
        expect(await row(empty)).toBe('null');
        expect(await row(null)).toBe('null');
    });

    it('shows a skeleton while loading', async () => {
        const tree = await row(null, true);
        expect(tree).not.toBe('null');
        expect(tree).not.toContain('Our work');
    });

    it('shows the video row with See all', async () => {
        const tree = await row({ ...empty, videos: [video] });
        for (const text of ['Our work', 'See all of our work', 'Watch video: Coating day']) expect(tree).toContain(text);
    });

    it('still offers the work screen when there are only photos or reviews', async () => {
        expect(await row({ ...empty, reviews: [review] })).toContain('See photos and reviews');
    });
});
