// PLACEHOLDER icons for the four team apps: the 826 mark (as a dark silhouette) on the role colour, with a small role badge.
// Replace with real artwork later. Needs sharp, which is not a dependency here:
//   NODE_PATH=<a node_modules with sharp> node scripts/make-placeholder-icons.mjs
import { createRequire } from 'module';
import { mkdirSync } from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const require = createRequire(import.meta.url);
const sharp = require('sharp');
const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const mark = path.join(root, 'packages/core/assets/logo-mark.png');
const DARK = '#0B0C0E';
const ROLES = {
    owner: { color: '#C9A24B', letters: 'OW' },
    sales: { color: '#2AD1CB', letters: 'SA' },
    operations: { color: '#4C8DFF', letters: 'OP' },
    system: { color: '#8B7CF6', letters: 'SY' },
};

/** The mark as one flat colour (its alpha is the shape) */
const silhouette = (width, fill) => sharp(mark).resize({ width }).ensureAlpha().extractChannel('alpha').linear(4, 0).toBuffer().then((alpha) =>
    sharp({ create: { width, height: Math.round((width * 134) / 480), channels: 3, background: fill } }).joinChannel(alpha).png().toBuffer());

const badge = (size, letters, bg, fg) => Buffer.from(`<svg width="${size}" height="${size}" xmlns="http://www.w3.org/2000/svg"><circle cx="${size / 2}" cy="${size / 2}" r="${size / 2}" fill="${bg}"/><text x="50%" y="50%" dy="0.35em" text-anchor="middle" font-family="DejaVu Sans, Arial, sans-serif" font-weight="700" font-size="${size * 0.46}" fill="${fg}">${letters}</text></svg>`);

async function layer({ color, letters }, { background, markFill, badgeBg, badgeFg, markWidth, badgeSize, badgeAt }) {
    const m = await silhouette(markWidth, markFill);
    const mh = Math.round((markWidth * 134) / 480);
    const parts = [{ input: m, left: Math.round((1024 - markWidth) / 2), top: Math.round(512 - mh / 2 - 60) }, { input: badge(badgeSize, letters, badgeBg, badgeFg), left: badgeAt[0], top: badgeAt[1] }];

    return sharp({ create: { width: 1024, height: 1024, channels: 4, background } }).composite(parts).png();
}

for (const [key, role] of Object.entries(ROLES)) {
    const out = path.join(root, 'apps', key, 'assets');
    mkdirSync(out, { recursive: true });
    await (await layer(role, { background: role.color, markFill: DARK, badgeBg: DARK, badgeFg: role.color, markWidth: 700, badgeSize: 230, badgeAt: [397, 640] })).flatten({ background: role.color }).toFile(path.join(out, 'icon.png'));
    // Adaptive: transparent foreground inside the 66% safe zone over the role colour, and a white one-colour version for themed icons
    await (await layer(role, { background: { r: 0, g: 0, b: 0, alpha: 0 }, markFill: DARK, badgeBg: DARK, badgeFg: role.color, markWidth: 520, badgeSize: 190, badgeAt: [417, 600] })).toFile(path.join(out, 'android-icon-foreground.png'));
    await (await layer(role, { background: { r: 0, g: 0, b: 0, alpha: 0 }, markFill: '#FFFFFF', badgeBg: '#FFFFFF', badgeFg: '#000000', markWidth: 520, badgeSize: 190, badgeAt: [417, 600] })).toFile(path.join(out, 'android-icon-monochrome.png'));
    // Splash: the mark and the badge on the dark ground (the plugin sets the background)
    await (await layer(role, { background: { r: 0, g: 0, b: 0, alpha: 0 }, markFill: role.color, markWidth: 640, badgeBg: role.color, badgeFg: DARK, badgeSize: 220, badgeAt: [402, 600] })).toFile(path.join(out, 'splash-icon.png'));
    await sharp(path.join(out, 'icon.png')).resize(48, 48).toFile(path.join(out, 'favicon.png'));
}
console.log('placeholder icons written for', Object.keys(ROLES).join(', '));
