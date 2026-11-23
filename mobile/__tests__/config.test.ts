import fs from 'fs';
import path from 'path';

const root = path.join(__dirname, '..');
const json = (file: string) => JSON.parse(fs.readFileSync(path.join(root, file), 'utf8'));
const sources = (dir: string): string[] => fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? sources(path.join(dir, e.name)) : /\.tsx?$/.test(e.name) ? [path.join(dir, e.name)] : []));

describe('app config', () => {
    it('is 0.2.0 (versionCode 2, buildNumber 2) and follows the phone light or dark setting', () => {
        const { expo } = json('app.json');
        expect(expo.version).toBe('0.2.0');
        expect(json('package.json').version).toBe('0.2.0');
        expect(expo.android.versionCode).toBe(2);
        expect(expo.ios.buildNumber).toBe('2');
        expect(expo.userInterfaceStyle).toBe('automatic');
    });
});

describe('theme tokens', () => {
    // Over a photo or a thumbnail the controls stay white on dark whatever the scheme; a QR code needs a white card
    const ALLOWED = [/CHROME = /, /backgroundColor: '#fff' \}\}/, /backgroundColor: '#000' \}\}/];

    it('are the only place colours are written: screens and parts read them from useTheme()', () => {
        const hits: string[] = [];

        for (const file of [...sources(path.join(root, 'app')), ...sources(path.join(root, 'src'))]) {
            if (file.endsWith(`${path.sep}theme.ts`)) continue;

            fs.readFileSync(file, 'utf8')
                .split('\n')
                .forEach((line, index) => {
                    if (/#[0-9a-fA-F]{3,8}\b|rgba?\(/.test(line) && !ALLOWED.some((allowed) => allowed.test(line))) hits.push(`${path.relative(root, file)}:${index + 1}: ${line.trim().slice(0, 100)}`);
                });
        }

        expect(hits).toEqual([]);
    });

    it('are not imported as the old fixed dark colours any more', () => {
        const old: string[] = [];

        for (const file of [...sources(path.join(root, 'app')), ...sources(path.join(root, 'src'))]) {
            if (/import \{[^}]*\bc\b[^}]*\} from '@\/theme'/.test(fs.readFileSync(file, 'utf8'))) old.push(path.relative(root, file));
        }

        expect(old).toEqual([]);
    });
});
