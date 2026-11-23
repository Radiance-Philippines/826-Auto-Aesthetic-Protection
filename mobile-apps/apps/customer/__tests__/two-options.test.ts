import { readdirSync, readFileSync, statSync } from 'fs';
import { join } from 'path';

/** Source files of the customer app (and the shared packages) that could draw a dropdown */
function sources(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (name === 'node_modules' || name === '__tests__') return [];
        if (statSync(path).isDirectory()) return sources(path);
        return /\.tsx$/.test(name) ? [path] : [];
    });
}

const root = join(__dirname, '..', '..', '..');

describe('two options are radios, not a dropdown', () => {
    it('only lists with 3+ or data-driven options use the Select sheet', () => {
        const uses = sources(join(root, 'apps', 'customer'))
            .concat(sources(join(root, 'packages')))
            .filter((file) => !file.endsWith(join('ui', 'Select.tsx')))
            .flatMap((file) => (readFileSync(file, 'utf8').match(/<Select\b[^>]*label="([^"]+)"/g) ?? []).map((tag) => `${file.split('/').slice(-1)[0]}: ${tag.match(/label="([^"]+)"/)![1]}`))
            .sort();

        // Type of unit: the 7 types the shop sets. Area: the branch's own list (plus Other).
        expect(uses).toEqual(['UnitFields.tsx: Type of unit', 'home-service.tsx: Area']);
    });
});
