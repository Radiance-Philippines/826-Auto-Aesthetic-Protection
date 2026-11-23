import { existsSync } from 'node:fs';
import net from 'node:net';

import { defineConfig } from '@playwright/test';

/** A free port, picked once: the workers load this file again and must see the same one */
async function freePort(variable = 'E2E_PORT'): Promise<number> {
    if (process.env[variable]) {
        return Number(process.env[variable]);
    }

    const port = await new Promise<number>((resolve, reject) => {
        const probe = net.createServer();
        probe.once('error', reject);
        probe.listen(0, '127.0.0.1', () => {
            const { port } = probe.address() as net.AddressInfo;
            probe.close(() => resolve(port));
        });
    });

    process.env[variable] = String(port);

    return port;
}

const port = await freePort();
// The stand-in for Radiance Flow (live updates), on its own port
const flowPort = await freePort('E2E_FLOW_PORT');

// ribs02 has its Chromium already; no browser is downloaded in CI
const ribsChromium = '/opt/flow-tools/chromium_headless_shell-1243/chrome-headless-shell-linux64/chrome-headless-shell';
const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || (existsSync(ribsChromium) ? ribsChromium : undefined);

export default defineConfig({
    testDir: 'tests/e2e',
    outputDir: 'test-results/artifacts',
    // One worker: the booking tests share one seeded database and a 5-a-minute booking limit
    workers: 1,
    fullyParallel: false,
    retries: process.env.CI ? 1 : 0,
    timeout: 45_000,
    expect: { timeout: 10_000 },
    reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }], ['junit', { outputFile: 'test-results/junit.xml' }]],
    use: {
        baseURL: `http://127.0.0.1:${port}`,
        locale: 'en-PH',
        timezoneId: 'Asia/Manila',
        colorScheme: 'light',
        reducedMotion: 'reduce',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        launchOptions: { executablePath },
    },
    projects: [
        { name: 'desktop', use: { viewport: { width: 1440, height: 900 } } },
        { name: 'phone', use: { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 } },
    ],
    webServer: [
        {
            command: 'node tests/e2e/fake-flow.mjs',
            env: { E2E_FLOW_PORT: String(flowPort) },
            url: `http://127.0.0.1:${flowPort}/health`,
            reuseExistingServer: false,
            timeout: 30_000,
            stdout: 'ignore',
            stderr: 'pipe',
        },
        {
            command: 'bash tests/e2e/serve.sh',
            env: { E2E_PORT: String(port), E2E_FLOW_PORT: String(flowPort) },
            url: `http://127.0.0.1:${port}/robots.txt`,
            reuseExistingServer: false,
            timeout: 180_000,
            stdout: 'ignore',
            stderr: 'pipe',
        },
    ],
});
