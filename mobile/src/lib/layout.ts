/** Where the 826 mark sits on a screen. Screen, Brand, sign-in and the opening animation all read these, so the intro lands on the real header logo with no jump. */
export const HEADER = {
    /** Screen's side padding: the mark's left edge */
    padX: 20,
    /** Screen's gap under the status bar inset */
    topGap: 12,
    /** Sign-in overrides Screen's top padding with this (no inset added) */
    signInTop: 48,
    /** The mark's height and its width/height ratio (assets/logo-mark.png is 480x134) */
    brandHeight: 14,
    brandRatio: 3.57,
} as const;
