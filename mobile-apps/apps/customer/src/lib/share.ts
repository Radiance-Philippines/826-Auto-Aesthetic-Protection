import { Share } from 'react-native';
import { API_URL } from './config';

/**
 * The site's own page for a code: `<site>/r/<code>`. The site is the API base
 * without a trailing slash or `/api`, with a demo copy's folder kept, so a demo
 * build shares https://dran.dev/demo/826/r/CODE.
 */
export function referralUrl(code: string, base: string = API_URL): string {
    return `${base.replace(/\/+$/, '').replace(/\/api$/, '')}/r/${encodeURIComponent(code)}`;
}

/** The plain text of a share: what the code gives, the code, and the link to open it */
export function referralMessage(code: string, discountLabel: string | null, base?: string): string {
    const text = discountLabel
        ? `Book your detailing at 826 Auto Aesthetic with my code ${code} and get ${discountLabel} on your first booking.`
        : `Book your detailing at 826 Auto Aesthetic with my code ${code}.`;

    return `${text}\n${referralUrl(code, base)}`;
}

export const shareReferral = (code: string, discountLabel: string | null) => Share.share({ message: referralMessage(code, discountLabel) });
