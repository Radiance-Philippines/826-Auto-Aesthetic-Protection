// The team app icons: the 826 mark and the app name, flat dark (#0B0C0E) on the app's colour.
// Writes the files each app.json references. Needs sharp, which is not a dependency here:
//   NODE_PATH=<a node_modules with sharp> node scripts/make-team-icons.mjs
import assert from 'assert/strict';
import { createRequire } from 'module';
import { readFileSync } from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const require = createRequire(import.meta.url);
const sharp = require('sharp');
const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const MARK = path.join(root, '../public/brand/826-mark.webp');
const FONT = path.join(root, 'node_modules/@expo-google-fonts/archivo/900Black/Archivo_900Black.ttf');
readFileSync(FONT); // Pango would silently use another font if this file were missing

const DARK = '#0B0C0E';
const CLEAR = { r: 0, g: 0, b: 0, alpha: 0 };
const NAMES = { owner: 'OWNER', sales: 'SALES', operations: 'OPS', system: 'SYSTEM' };
const SIZE = 1024;
// Android keeps a 66dp circle of the 108dp adaptive canvas visible under every mask
const SAFE_RADIUS = (SIZE * 66) / 108 / 2;

/** The teal faces of the mark as a hard-edged alpha shape. Its black outline is dropped, so the three digits stay apart when flat. */
async function markShape() {
    // Cut at 4x so the later downscale gives smooth edges; the bevel's half tones become fully in or fully out
    const { data, info } = await sharp(MARK).ensureAlpha().resize({ width: 3332 }).raw().toBuffer({ resolveWithObject: true });
    const alpha = Buffer.alloc(info.width * info.height);
    for (let i = 0; i < alpha.length; i++) {
        alpha[i] = data[i * 4 + 1] > 36 && data[i * 4 + 3] > 80 ? 255 : 0;
    }

    return sharp(alpha, { raw: { width: info.width, height: info.height, channels: 1 } }).median(9).png().toBuffer();
}

/** A name as an alpha shape, every name at the same type size */
async function nameShape(text) {
    const { data, info } = await sharp({ text: { text: `<span letter_spacing="2048">${text}</span>`, font: 'Archivo Heavy', fontfile: FONT, dpi: 1200, rgba: true } }).png().toBuffer({ resolveWithObject: true });

    return { input: await sharp(data).extractChannel('alpha').png().toBuffer(), width: info.width, height: info.height };
}

/** One flat colour cut out by an alpha shape, at the given width */
async function paint(shape, width, color) {
    const { data, info } = await sharp(shape).resize({ width }).extractChannel(0).raw().toBuffer({ resolveWithObject: true });
    const input = await sharp({ create: { width: info.width, height: info.height, channels: 3, background: color } })
        .joinChannel(data, { raw: { width: info.width, height: info.height, channels: 1 } })
        .png()
        .toBuffer();

    return { input, width: info.width, height: info.height };
}

const mark = await markShape();
const names = Object.fromEntries(await Promise.all(Object.entries(NAMES).map(async ([key, text]) => [key, await nameShape(text)])));
const widestName = Math.max(...Object.values(names).map((n) => n.width));

/** Mark over name, centred. `markWidth` sets the whole lockup's scale; the widest name is 90% of it. */
async function lockup(key, color, markWidth) {
    const top = await paint(mark, markWidth, color);
    const name = await paint(names[key].input, Math.round((names[key].width * markWidth * 0.9) / widestName), color);
    const gap = Math.round(markWidth * 0.08);
    const y = Math.round((SIZE - top.height - gap - name.height) / 2);
    const parts = [
        { ...top, left: Math.round((SIZE - top.width) / 2), top: y },
        { ...name, left: Math.round((SIZE - name.width) / 2), top: y + top.height + gap },
    ];

    return sharp({ create: { width: SIZE, height: SIZE, channels: 4, background: CLEAR } }).composite(parts).png().toBuffer();
}

/** Furthest visible pixel from the centre */
async function reach(png) {
    const alpha = await sharp(png).extractChannel('alpha').raw().toBuffer();
    let max = 0;
    for (let i = 0; i < alpha.length; i++) {
        if (alpha[i] > 8) max = Math.max(max, Math.hypot((i % SIZE) - SIZE / 2, Math.floor(i / SIZE) - SIZE / 2));
    }

    return max;
}

for (const key of Object.keys(NAMES)) {
    const dir = path.join(root, 'apps', key);
    const { expo } = JSON.parse(readFileSync(path.join(dir, 'app.json'), 'utf8'));
    const color = expo.extra.team.accent;
    assert.equal(expo.android.adaptiveIcon.backgroundColor, color, `${key}: adaptive background must be the app colour`);
    const splash = expo.plugins.find((p) => Array.isArray(p) && p[0] === 'expo-splash-screen')[1];
    const out = (file) => path.join(dir, file);

    // iOS and legacy: opaque, no alpha channel
    const icon = await sharp(await lockup(key, DARK, 800)).flatten({ background: color }).removeAlpha().png().toBuffer();
    await sharp(icon).toFile(out(expo.icon));
    await sharp(icon).resize(48, 48).toFile(out('assets/favicon.png'));

    for (const [file, fill] of [[expo.android.adaptiveIcon.foregroundImage, DARK], [expo.android.adaptiveIcon.monochromeImage, '#FFFFFF']]) {
        const layer = await lockup(key, fill, 560);
        assert.ok((await reach(layer)) <= SAFE_RADIUS, `${key}: ${file} leaves the adaptive safe zone`);
        await sharp(layer).toFile(out(file));
    }

    // Splash sits on the dark ground the plugin paints, so the lockup takes the app colour
    await sharp(await lockup(key, color, 800)).toFile(out(splash.image));
    console.log(`${key}: icons written`);
}
