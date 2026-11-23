import { Share } from 'react-native';

/**
 * The plain referral text. The site has no public page for a code, so it
 * shares the code and a line about it, never a link.
 */
export function referralMessage(code: string, discountLabel: string | null): string {
    return discountLabel
        ? `Book your detailing at 826 Auto Aesthetic with my code ${code} and get ${discountLabel} on your first booking.`
        : `Book your detailing at 826 Auto Aesthetic with my code ${code}.`;
}

export const shareReferral = (code: string, discountLabel: string | null) => Share.share({ message: referralMessage(code, discountLabel) });
