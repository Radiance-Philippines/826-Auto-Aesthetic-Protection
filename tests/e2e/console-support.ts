import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';

/** The React admin's pages must have no WCAG A/AA violation axe can find (it is all our own markup) */
export async function expectAccessible(page: Page) {
    const { violations } = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();

    expect(violations.map((violation) => `${violation.id}: ${violation.help} (${violation.nodes.length}), e.g. ${violation.nodes[0]?.target.join(' ')}`)).toEqual([]);
}
