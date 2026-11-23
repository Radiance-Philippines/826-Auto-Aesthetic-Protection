import { Image } from 'expo-image';
import { HEADER } from '@/lib/layout';

/** The small "826" mark above a screen's title (design: top left) */
export function Brand({ height = HEADER.brandHeight }: { height?: number }) {
    return <Image source={require('../../assets/logo-mark.png')} accessibilityLabel="826" style={{ width: height * HEADER.brandRatio, height }} contentFit="contain" />;
}
