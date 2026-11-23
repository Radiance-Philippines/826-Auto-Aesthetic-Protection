import fs from 'fs';
import path from 'path';

const KEY = 'system';
const EXPECTED = { label: '826 System', pkg: 'ph.radianceph.aap826.system', version: '0.1.1', versionCode: 2, accent: '#8B7CF6' };
const root = path.join(__dirname, '..');
const { expo } = JSON.parse(fs.readFileSync(path.join(root, 'app.json'), 'utf8'));

// What the app tells the shared screens it is (expo-constants reads this same config on the phone)
jest.mock('expo-constants', () => ({ __esModule: true, default: { expoConfig: require('../app.json').expo } }));

describe(`826 ${KEY} app`, () => {
    it('mounts the team features as its own appKey, label and colour', () => {
        const { getTeamApp } = require('@826/ops-features') as typeof import('@826/ops-features');
        expect(getTeamApp()).toEqual({ appKey: KEY, label: EXPECTED.label, accent: EXPECTED.accent });
    });

    it('has its own name, ids, version and colour', () => {
        expect(expo.name).toBe(EXPECTED.label);
        expect(expo.slug).toBe(`826-${KEY}`);
        expect(expo.scheme).toBe(`aap826${KEY}`);
        expect(expo.android.package).toBe(EXPECTED.pkg);
        expect(expo.ios.bundleIdentifier).toBe(EXPECTED.pkg);
        expect(expo.version).toBe(EXPECTED.version);
        expect(expo.android.versionCode).toBe(EXPECTED.versionCode);
        expect(expo.ios.buildNumber).toBe(String(EXPECTED.versionCode));
        expect(expo.android.adaptiveIcon.backgroundColor).toBe(EXPECTED.accent);
        expect(JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8')).version).toBe(EXPECTED.version);
    });

    it('has its icon, adaptive icons and splash', () => {
        for (const file of [expo.icon, expo.android.adaptiveIcon.foregroundImage, expo.android.adaptiveIcon.monochromeImage]) expect(fs.existsSync(path.join(root, file))).toBe(true);
        const splash = expo.plugins.find((p: unknown) => Array.isArray(p) && p[0] === 'expo-splash-screen');
        expect(fs.existsSync(path.join(root, splash[1].image))).toBe(true);
    });

    it('takes every route from the shared team features and holds no screen of its own', () => {
        const walk = (dir: string): string[] => fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(path.join(dir, e.name)) : [path.join(dir, e.name)]));
        const shared = path.join(root, '../../packages/ops-features/app');
        const mine = path.join(root, 'app');
        expect(walk(mine).map((f) => path.relative(mine, f)).sort()).toEqual(walk(shared).map((f) => path.relative(shared, f)).sort());

        for (const file of walk(mine)) {
            const route = path.relative(mine, file).replace(/\.tsx$/, '');
            expect(fs.readFileSync(file, 'utf8')).toBe(`export { default } from '@826/ops-features/app/${route}';\n`);
        }
    });

    it('signs with the shared team keystore variables (AAP826STAFF_*) and never holds a secret', () => {
        const script = fs.readFileSync(path.join(root, 'scripts/build-apk.sh'), 'utf8');
        expect(script).toContain('backups/826-staff-app/keystore.env');
        expect(script).toContain('AAP826STAFF_STORE_FILE');
        expect(script).toContain(`aap826-${KEY}-$VER.apk`);
        expect(script).not.toMatch(/PASSWORD=/);
    });
});
