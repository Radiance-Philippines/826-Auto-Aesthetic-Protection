import { Image } from 'expo-image';
import { ArrowRight, Images, Play, X } from 'lucide-react-native';
import { useEffect, useState } from 'react';
import { FlatList, Linking, Modal, Pressable, ScrollView, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import type { Showcase, ShowcasePhoto, ShowcaseReview, ShowcaseVideo } from '@/api';
import { monthYear, radius, target, useTheme, Button, Card, PressScale, useReducedMotion, Skeleton, Stars, Text } from '@826/core';
import type { Loaded } from '@826/core';

/** Over a photo or a video thumbnail the buttons are always white on dark, in light mode too */
const CHROME = { fg: '#FFFFFF', bg: 'rgba(0,0,0,0.6)', line: 'rgba(255,255,255,0.18)' } as const;

/** The Our work section shows only when the admin has put something in it */
export const hasShowcase = (showcase: Showcase | null | undefined): showcase is Showcase => !!showcase && showcase.videos.length + showcase.photos.length + showcase.reviews.length > 0;

/** A YouTube thumbnail with a play badge. Opens in the YouTube app or the browser. */
export function VideoCard({ video, width }: { video: ShowcaseVideo; width?: number }) {
    const c = useTheme();
    return (
        <PressScale accessibilityRole="link" accessibilityLabel={`Watch video: ${video.title}`} onPress={() => void Linking.openURL(video.url)} style={{ width, minHeight: target, gap: 8 }}>
            <View style={{ aspectRatio: 16 / 9, borderRadius: radius.lg, overflow: 'hidden', backgroundColor: c.surface2, borderWidth: 1, borderColor: c.line }}>
                <Image source={{ uri: video.thumbnail }} accessibilityIgnoresInvertColors style={{ flex: 1 }} contentFit="cover" transition={150} />
                <View style={{ position: 'absolute', top: 0, bottom: 0, left: 0, right: 0, alignItems: 'center', justifyContent: 'center' }}>
                    <View style={{ width: 48, height: 48, borderRadius: 24, backgroundColor: CHROME.bg, borderWidth: 1, borderColor: CHROME.line, alignItems: 'center', justifyContent: 'center' }}>
                        <Play size={22} color={CHROME.fg} fill={CHROME.fg} />
                    </View>
                </View>
            </View>
            <Text v="title" numberOfLines={2} style={{ fontSize: 15, lineHeight: 20 }}>
                {video.title}
            </Text>
            {video.caption ? (
                <Text v="small" numberOfLines={2}>
                    {video.caption}
                </Text>
            ) : null}
        </PressScale>
    );
}

export function ReviewCard({ review }: { review: ShowcaseReview }) {
    const c = useTheme();
    const meta = [review.service, review.branch, review.date ? monthYear(review.date) : null].filter(Boolean).join(' · ');

    return (
        <Card accessible accessibilityLabel={`Review by ${review.name}, ${review.rating} out of 5. ${review.body}${meta ? `. ${meta}` : ''}`} style={{ gap: 10 }}>
            <Stars value={review.rating} size={18} />
            <Text>{`“${review.body}”`}</Text>
            {review.photo ? <Image source={{ uri: review.photo.src }} accessibilityIgnoresInvertColors style={{ aspectRatio: 4 / 3, borderRadius: radius.md, backgroundColor: c.surface2 }} contentFit="cover" /> : null}
            <View style={{ gap: 2 }}>
                <Text weight="bold">{review.name}</Text>
                {meta ? <Text v="small">{meta}</Text> : null}
            </View>
        </Card>
    );
}

/** A 2-column grid. Tap a photo to open it full screen. */
export function PhotoGrid({ photos }: { photos: ShowcasePhoto[] }) {
    const c = useTheme();
    const [open, setOpen] = useState<number | null>(null);

    return (
        <>
            <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
                {photos.map((photo, index) => (
                    <Pressable key={photo.id} accessibilityRole="imagebutton" accessibilityLabel={`Open photo: ${photo.alt}`} onPress={() => setOpen(index)} style={({ pressed }) => ({ width: '48.5%', aspectRatio: 1, opacity: pressed ? 0.85 : 1 })}>
                        <Image source={{ uri: photo.image.src }} accessibilityIgnoresInvertColors style={{ flex: 1, borderRadius: radius.md, backgroundColor: c.surface2 }} contentFit="cover" transition={150} />
                    </Pressable>
                ))}
            </View>
            <PhotoViewer photos={photos} index={open} onClose={() => setOpen(null)} />
        </>
    );
}

/** Full-screen photos you swipe through. The close button is the way out (no Back-key reliance). */
export function PhotoViewer({ photos, index, onClose }: { photos: ShowcasePhoto[]; index: number | null; onClose: () => void }) {
    const insets = useSafeAreaInsets();
    const { width, height } = useWindowDimensions();
    const [current, setCurrent] = useState(0);
    const motion = useReducedMotion();

    useEffect(() => {
        if (index !== null) setCurrent(index);
    }, [index]);

    return (
        <Modal visible={index !== null} transparent animationType={motion ? 'none' : 'fade'} onRequestClose={onClose} statusBarTranslucent navigationBarTranslucent>
            <View accessibilityViewIsModal style={{ flex: 1, backgroundColor: '#000' }}>
                {index !== null ? (
                    <FlatList
                        data={photos}
                        horizontal
                        pagingEnabled
                        showsHorizontalScrollIndicator={false}
                        keyExtractor={(photo) => String(photo.id)}
                        initialScrollIndex={index}
                        getItemLayout={(_, i) => ({ length: width, offset: width * i, index: i })}
                        onMomentumScrollEnd={(event) => setCurrent(Math.round(event.nativeEvent.contentOffset.x / width))}
                        renderItem={({ item }) => (
                            <View style={{ width, height, alignItems: 'center', justifyContent: 'center' }}>
                                <Image source={{ uri: item.image.src }} accessibilityLabel={item.alt} accessibilityIgnoresInvertColors style={{ width, height: height * 0.8 }} contentFit="contain" />
                            </View>
                        )}
                    />
                ) : null}
                <View style={{ position: 'absolute', top: insets.top + 8, left: 16, right: 8, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
                    <Text accessibilityLiveRegion="polite" weight="semi" color={CHROME.fg} style={{ backgroundColor: CHROME.bg, borderRadius: radius.pill, paddingHorizontal: 12, paddingVertical: 6, fontSize: 14 }}>
                        {`${current + 1} of ${photos.length}`}
                    </Text>
                    <Pressable accessibilityRole="button" accessibilityLabel="Close photo" onPress={onClose} style={{ width: target, height: target, borderRadius: target / 2, backgroundColor: CHROME.bg, alignItems: 'center', justifyContent: 'center' }}>
                        <X size={24} color={CHROME.fg} />
                    </Pressable>
                </View>
                {photos[current]?.categoryLabel ? (
                    <View pointerEvents="none" style={{ position: 'absolute', bottom: insets.bottom + 16, left: 16, right: 16, alignItems: 'center' }}>
                        <Text color={CHROME.fg} style={{ backgroundColor: CHROME.bg, borderRadius: radius.pill, paddingHorizontal: 14, paddingVertical: 6, fontSize: 14 }}>
                            {photos[current].categoryLabel}
                        </Text>
                    </View>
                ) : null}
            </View>
        </Modal>
    );
}

/** Home's Our work row: skeleton while loading, nothing when there is no content or the load failed */
export function WorkPreview({ work, onSeeAll }: { work: Pick<Loaded<Showcase>, 'data' | 'loading'>; onSeeAll: () => void }) {
    if (work.loading) {
        return (
            <View style={{ gap: 12 }}>
                <Skeleton height={22} width={140} />
                <Skeleton height={150} />
            </View>
        );
    }

    if (!hasShowcase(work.data)) {
        return null;
    }

    return (
        <View style={{ gap: 8 }}>
            <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 4 }}>
                <Text v="title" accessibilityRole="header" style={{ fontSize: 19 }}>
                    Our work
                </Text>
                <Button small kind="link" icon={ArrowRight} label="See all" accessibilityLabel="See all of our work" onPress={onSeeAll} />
            </View>
            {work.data.videos.length ? (
                <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ marginHorizontal: -20 }} contentContainerStyle={{ paddingHorizontal: 20, gap: 12 }}>
                    {work.data.videos.map((video) => (
                        <VideoCard key={video.id} video={video} width={240} />
                    ))}
                </ScrollView>
            ) : (
                <Button kind="secondary" icon={Images} label="See photos and reviews" onPress={onSeeAll} />
            )}
        </View>
    );
}
