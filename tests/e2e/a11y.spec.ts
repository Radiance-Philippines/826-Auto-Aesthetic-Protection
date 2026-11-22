import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { KEY_PAGES, open } from './support';

/**
 * Accessibility smoke: axe (WCAG 2.0/2.1/2.2 A and AA rules) on the key pages, in light and dark.
 * Any violation fails the run. A rule that is a known open bug goes in KNOWN with the issue
 * that tracks it, so it is visible and the rest still guards the page.
 */
const KNOWN: Record<string, string> = {
};

for (const { name, path } of KEY_PAGES.filter((page) => page.status === 200)) {
    for (const scheme of ['light', 'dark'] as const) {
        test(`accessibility: ${name} (${scheme})`, async ({ page }) => {
            await page.emulateMedia({ colorScheme: scheme });
            await open(page, path);

            const { violations } = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
            const found = violations.filter((violation) => !(`${name}:${violation.id}` in KNOWN));

            expect(
                found.map((violation) => `${violation.id} (${violation.impact}): ${violation.help} - ${violation.nodes.length} element(s), e.g. ${violation.nodes[0]?.target.join(' ')}`),
            ).toEqual([]);
        });
    }
}
