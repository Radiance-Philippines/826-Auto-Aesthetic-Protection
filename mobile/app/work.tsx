import { api, type Showcase } from '@/api';
import { hasShowcase, PhotoGrid, ReviewCard, VideoCard } from '@/features/Work';
import { useLoad } from '@/hooks/useLoad';
import { Screen } from '@/ui/Screen';
import { Skeleton } from '@/ui/Skeleton';
import { EmptyState, ErrorState } from '@/ui/States';
import { Text } from '@/ui/Text';

/** Our work: the same videos, photos and approved reviews the website shows, put in by the 826 admin */
export default function WorkScreen() {
    const { data, error, loading, reload } = useLoad<Showcase>(() => api.showcase());

    return (
        <Screen back="Home" title="Our work" onRefresh={reload}>
            {loading ? (
                <>
                    <Skeleton height={190} />
                    <Skeleton height={190} />
                    <Skeleton height={120} />
                </>
            ) : error ? (
                <ErrorState result={error} onRetry={reload} />
            ) : hasShowcase(data) ? (
                <>
                    {data.videos.length ? (
                        <>
                            <Text v="title" accessibilityRole="header" style={{ fontSize: 19 }}>
                                Videos
                            </Text>
                            {data.videos.map((video) => (
                                <VideoCard key={video.id} video={video} />
                            ))}
                        </>
                    ) : null}
                    {data.photos.length ? (
                        <>
                            <Text v="title" accessibilityRole="header" style={{ fontSize: 19, marginTop: 8 }}>
                                Photos
                            </Text>
                            <PhotoGrid photos={data.photos} />
                        </>
                    ) : null}
                    {data.reviews.length ? (
                        <>
                            <Text v="title" accessibilityRole="header" style={{ fontSize: 19, marginTop: 8 }}>
                                Reviews
                            </Text>
                            {data.reviews.map((review) => (
                                <ReviewCard key={review.id} review={review} />
                            ))}
                        </>
                    ) : null}
                </>
            ) : (
                <EmptyState title="Nothing here yet" body="Our videos, photos and customer reviews show up here." />
            )}
        </Screen>
    );
}
