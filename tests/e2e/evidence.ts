import { mkdirSync } from 'node:fs';

import type { Page, TestInfo } from '@playwright/test';

/**
 * Screenshot evidence for the Board cards: only when E2E_EVIDENCE=1 (CI stays fast).
 * Files land in test-results/evidence/<card>/<name>-<project>.png
 */
export async function evidence(page: Page, info: TestInfo, card: string, name: string) {
    if (!process.env.E2E_EVIDENCE) {
        return;
    }

    const dir = `test-results/evidence/${card}`;
    mkdirSync(dir, { recursive: true });
    await page.screenshot({ path: `${dir}/${name}-${info.project.name}.png`, fullPage: true });
}
