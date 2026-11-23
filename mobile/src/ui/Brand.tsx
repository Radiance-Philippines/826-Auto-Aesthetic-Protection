import { Image } from 'expo-image';

/** The small "826" mark above a screen's title (design: top left) */
export function Brand({ height = 14 }: { height?: number }) {
    return <Image source={require('../../assets/logo-mark.png')} accessibilityLabel="826" style={{ width: height * 3.57, height }} contentFit="contain" />;
}
