import { Image } from 'expo-image';
import * as SplashScreen from 'expo-splash-screen';
import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Animated, Easing, Modal, Pressable, StyleSheet, useWindowDimensions } from 'react-native';
import Svg, { Defs, Ellipse, RadialGradient, Stop } from 'react-native-svg';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useTheme } from '@/context/theme';
import { carSize, decideIntro, headerLogoSpot, logoSize, PHASE, PUFF_BASE, puffInFront, REDUCED_FADE, sampleIntro, type IntroMode, type Layout, type Spot } from '@/lib/intro';

/*
 * The opening animation (owner brief, board card 678). The maths is src/lib/intro.ts (introFrame); here it is sampled into
 * keyframes of ONE native-driven value (`t`, milliseconds), so there is no per-frame JS state. The overlay sits above the
 * router in its own window while fonts and the sign-in restore load. It waits at the settled mark until the app is ready
 * (`ready`), then glides the mark onto the real header logo and fades the background away. A tap ends it (200 ms fade).
 */
export const SKIP_MS = 200;
/** Never leave a blocking overlay up for ever if the app never reports ready */
const GIVE_UP_MS = 10000;

const ip = (t: Animated.Value, input: number[], output: number[] | string[]) => t.interpolate({ inputRange: input, outputRange: output as number[], extrapolate: 'clamp' });

type PuffKeys = ReturnType<typeof sampleIntro>['puffs'][number];

/** One smoke circle: a soft radial gradient, moved and faded by native-driven interpolations */
const SmokePuff = memo(function SmokePuff({ keys, t, color, gain }: { keys: PuffKeys; t: Animated.Value; color: string; gain: number }) {
    return (
        <Animated.View
            pointerEvents="none"
            style={{
                position: 'absolute',
                left: 0,
                top: 0,
                width: PUFF_BASE,
                height: PUFF_BASE,
                opacity: ip(t, keys.ms, keys.opacity.map((o) => o * gain)),
                transform: [{ translateX: ip(t, keys.ms, keys.x) }, { translateY: ip(t, keys.ms, keys.y) }, { scale: ip(t, keys.ms, keys.scale) }],
            }}
        >
            <Svg width={PUFF_BASE} height={PUFF_BASE} viewBox="0 0 128 128">
                <Defs>
                    <RadialGradient id="puff" cx="0.5" cy="0.5" r="0.5">
                        <Stop offset="0" stopColor={color} stopOpacity={0.9} />
                        <Stop offset="0.45" stopColor={color} stopOpacity={0.55} />
                        <Stop offset="1" stopColor={color} stopOpacity={0} />
                    </RadialGradient>
                </Defs>
                <Ellipse cx={64} cy={64} rx={64} ry={64} fill="url(#puff)" />
            </Svg>
        </Animated.View>
    );
});

type Props = {
    mode: IntroMode;
    /** Fonts loaded and sign-in restored: the screen under the overlay is there */
    ready: boolean;
    /** Where the header logo is on the first screen; null = there is none (offline screen): the mark just fades out */
    spot: Spot | null;
    onDone: () => void;
};

/** The overlay. The gate below decides whether to mount it. */
export function Intro({ mode, ready, spot, onDone }: Props) {
    const c = useTheme();
    const { width: W, height: H } = useWindowDimensions();
    const t = useRef(new Animated.Value(0)).current;
    const root = useRef(new Animated.Value(1)).current;
    const logoFade = useRef(new Animated.Value(0)).current;
    const [held, setHeld] = useState(false);
    const [skipped, setSkipped] = useState(false);
    const [late, setLate] = useState(false);
    const finished = useRef(false);
    const reduced = mode === 'reduced';
    const go = ready || late;

    const finish = useCallback(() => {
        if (!finished.current) {
            finished.current = true;
            onDone();
        }
    }, [onDone]);

    const logo = logoSize(W);
    const target: Spot = spot ?? { x: W / 2 - logo.w / 2, y: H * 0.37 - logo.h / 2, w: logo.w, h: logo.h };
    const layout: Layout = useMemo(() => ({ W, H, spot: target }), [W, H, target.x, target.y, target.w, target.h]); // eslint-disable-line react-hooks/exhaustive-deps
    const keys = useMemo(() => (reduced ? null : sampleIntro(layout)), [reduced, layout]);

    // The overlay covers the splash from its first frame
    useEffect(() => {
        try {
            void SplashScreen.hideAsync().catch(() => {});
        } catch {
            // no splash to hide
        }

        const giveUp = setTimeout(() => setLate(true), GIVE_UP_MS);
        const run = reduced
            ? Animated.timing(logoFade, { toValue: 1, duration: REDUCED_FADE * 1000, easing: Easing.out(Easing.quad), useNativeDriver: true })
            : Animated.timing(t, { toValue: PHASE.hold * 1000, duration: PHASE.hold * 1000, easing: Easing.linear, useNativeDriver: true });
        run.start(({ finished: ok }) => {
            if (ok) {
                setHeld(true);
            }
        });

        return () => {
            clearTimeout(giveUp);
            run.stop();
        };
        // plays once, when the overlay mounts
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Settled and the app is ready: glide to the header and fade the background (or, no header logo, fade everything)
    useEffect(() => {
        if (!held || !go || skipped) {
            return;
        }

        if (reduced) {
            finish();

            return;
        }

        const run = Animated.timing(t, { toValue: PHASE.end * 1000, duration: (PHASE.end - PHASE.hold) * 1000, easing: Easing.linear, useNativeDriver: true });
        run.start(({ finished: ok }) => {
            if (!ok) {
                return;
            }

            if (spot) {
                finish();
            } else {
                Animated.timing(root, { toValue: 0, duration: SKIP_MS, useNativeDriver: true }).start(finish);
            }
        });

        return () => run.stop();
    }, [held, go, skipped, reduced, spot, t, root, finish]);

    // A tap (or Back) ends it: once the app is ready, a 200 ms fade to the screen under it
    useEffect(() => {
        if (!skipped || !go) {
            return;
        }

        t.stopAnimation();
        const run = Animated.timing(root, { toValue: 0, duration: SKIP_MS, useNativeDriver: true });
        run.start(({ finished: ok }) => {
            if (ok) {
                finish();
            }
        });

        return () => run.stop();
    }, [skipped, go, t, root, finish]);

    const skip = useCallback(() => setSkipped(true), []);
    const car = carSize(W);

    return (
        <Modal visible transparent animationType="none" statusBarTranslucent navigationBarTranslucent onRequestClose={skip}>
            <Animated.View style={[StyleSheet.absoluteFill, { opacity: root }]} testID="intro">
                <Pressable style={StyleSheet.absoluteFill} onPress={skip} accessible={false} importantForAccessibility="no-hide-descendants" accessibilityElementsHidden testID="intro-tap">
                    {reduced || !keys ? (
                        <>
                            <Animated.View pointerEvents="none" style={[StyleSheet.absoluteFill, { backgroundColor: c.bg }]} />
                            <Animated.View pointerEvents="none" style={{ position: 'absolute', left: target.x, top: target.y, width: target.w, height: target.h, opacity: logoFade }}>
                                <Image source={require('../../assets/logo-mark.png')} style={StyleSheet.absoluteFill} contentFit="contain" />
                            </Animated.View>
                        </>
                    ) : (
                        <>
                            <Animated.View pointerEvents="none" style={[StyleSheet.absoluteFill, { backgroundColor: c.bg, opacity: ip(t, keys.ms, keys.bg) }]} />
                            <Animated.View pointerEvents="none" style={[StyleSheet.absoluteFill, { transform: [{ translateX: ip(t, keys.ms, keys.shakeX) }, { translateY: ip(t, keys.ms, keys.shakeY) }] }]}>
                                {keys.puffs.map((p, i) => (puffInFront(i) ? null : <SmokePuff key={i} keys={p} t={t} color={c.intro.smoke} gain={c.intro.smokeGain} />))}
                                <Animated.View
                                    style={{
                                        position: 'absolute',
                                        left: 0,
                                        top: 0,
                                        width: car.w,
                                        height: car.h,
                                        opacity: ip(t, keys.ms, keys.carOpacity),
                                        transform: [{ translateX: ip(t, keys.ms, keys.carX) }, { translateY: ip(t, keys.ms, keys.carY) }, { rotate: ip(t, keys.ms, keys.carRotate.map((d) => `${d.toFixed(3)}deg`)) }, { scale: ip(t, keys.ms, keys.carScale) }],
                                    }}
                                >
                                    {/* Ground shadow, then the car mirrored so the nose leads left */}
                                    <Svg width={car.w} height={car.h * 0.3} style={{ position: 'absolute', left: 0, top: car.h * 0.76 }} viewBox="0 0 100 30">
                                        <Defs>
                                            <RadialGradient id="ground" cx="0.5" cy="0.5" r="0.5">
                                                <Stop offset="0" stopColor={c.intro.ground} stopOpacity={0.55} />
                                                <Stop offset="1" stopColor={c.intro.ground} stopOpacity={0} />
                                            </RadialGradient>
                                        </Defs>
                                        <Ellipse cx={50} cy={15} rx={50} ry={14} fill="url(#ground)" />
                                    </Svg>
                                    <Image source={require('../../assets/porsche-white.webp')} style={{ width: car.w, height: car.h, transform: [{ scaleX: -1 }] }} contentFit="contain" />
                                </Animated.View>
                                {keys.puffs.map((p, i) => (puffInFront(i) ? <SmokePuff key={i} keys={p} t={t} color={c.intro.smoke} gain={c.intro.smokeGain} /> : null))}
                                <Animated.View
                                    style={{
                                        position: 'absolute',
                                        left: 0,
                                        top: 0,
                                        width: logo.w,
                                        height: logo.h,
                                        opacity: ip(t, keys.ms, keys.logoOpacity),
                                        transform: [{ translateX: ip(t, keys.ms, keys.logoX) }, { translateY: ip(t, keys.ms, keys.logoY) }, { scale: ip(t, keys.ms, keys.logoScale) }],
                                    }}
                                >
                                    <Image source={require('../../assets/logo-mark.png')} style={StyleSheet.absoluteFill} contentFit="contain" />
                                </Animated.View>
                            </Animated.View>
                        </>
                    )}
                </Pressable>
            </Animated.View>
        </Modal>
    );
}

/**
 * Lives in Shell, outside the ready gate, so the app loads behind it. Decides once per cold start (in memory), reading the phone's
 * remove-animations setting: full intro, or the 300 ms logo fade. Never replays on tab changes or hot reload of a screen.
 */
function IntroGateOn({ ready, signedIn, offline }: { ready: boolean; signedIn: boolean; offline: boolean }) {
    const insets = useSafeAreaInsets();
    const [mode, setMode] = useState<IntroMode | null>(null);
    const [done, setDone] = useState(false);

    useEffect(() => {
        void decideIntro().then(setMode);
    }, []);

    const onDone = useCallback(() => setDone(true), []);

    if (!mode || done) {
        return null;
    }

    return <Intro mode={mode} ready={ready} spot={offline ? null : headerLogoSpot(insets.top, signedIn)} onDone={onDone} />;
}

/** The opening animation is paused by the owner: off unless the build sets EXPO_PUBLIC_INTRO=1 (read here, at render, so jest can flip it) */
export function IntroGate(props: { ready: boolean; signedIn: boolean; offline: boolean }) {
    return process.env.EXPO_PUBLIC_INTRO === '1' ? <IntroGateOn {...props} /> : null;
}
