import { expect, type Locator } from '@playwright/test';

/** On a phone a dialog is a sheet that scrolls into place: touching it on the way would swipe it away */
export async function settled(dialog: Locator) {
    let last = Number.NaN;
    await expect
        .poll(
            async () => {
                const top = (await dialog.boundingBox())?.y ?? Number.NaN;
                const still = top === last;
                last = top;

                return still;
            },
            { intervals: [250] },
        )
        .toBe(true);
}
