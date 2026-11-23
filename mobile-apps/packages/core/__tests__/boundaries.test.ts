import fs from 'fs';
import path from 'path';

const root = path.join(__dirname, '../../..');

const sources = (dir: string): string[] =>
    fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => (e.name === 'node_modules' || e.name === 'android' || e.name === 'dist' ? [] : e.isDirectory() ? sources(path.join(dir, e.name)) : /\.(tsx?|js)$/.test(e.name) ? [path.join(dir, e.name)] : []));

/** Every module a folder's files import (from, require, jest.mock, import()) */
const imports = (dir: string): { file: string; spec: string }[] =>
    sources(path.join(root, dir)).flatMap((file) => [...fs.readFileSync(file, 'utf8').matchAll(/(?:from\s+|require\(\s*|import\(\s*|jest\.mock\(\s*)'([^']+)'/g)].map((m) => ({ file: path.relative(root, file), spec: m[1] })));

const offenders = (dir: string, banned: RegExp) => imports(dir).filter(({ spec }) => banned.test(spec)).map(({ file, spec }) => `${file} imports ${spec}`);

describe('who may import whom', () => {
    it('the customer app does not import the team features', () => {
        expect(offenders('apps/customer', /ops-features|apps\/(owner|sales|operations|system)/)).toEqual([]);
    });

    it('the team features do not import the customer app (or its @/ paths: they use relative ones)', () => {
        expect(offenders('packages/ops-features', /apps\/customer|^@\/|aap826-mobile/)).toEqual([]);
    });

    it('core imports neither the team features nor any app', () => {
        expect(offenders('packages/core', /ops-features|^@\/|apps\//)).toEqual([]);
    });

    it('a thin team app has no source of its own beyond config and tests (its routes only re-export the shared ones)', () => {
        for (const app of ['owner', 'sales', 'operations', 'system']) {
            const own = sources(path.join(root, 'apps', app)).map((f) => path.relative(path.join(root, 'apps', app), f));
            expect(own.filter((f) => !/^(__tests__\/|app\/|[a-z.-]+\.js$)/.test(f))).toEqual([]);
        }
    });

    it('the customer app and the team apps list the packages they use', () => {
        const pkg = (p: string) => JSON.parse(fs.readFileSync(path.join(root, p, 'package.json'), 'utf8')).dependencies;
        expect(Object.keys(pkg('apps/customer'))).toContain('@826/core');
        expect(Object.keys(pkg('apps/customer'))).not.toContain('@826/ops-features');
        for (const app of ['owner', 'sales', 'operations', 'system']) expect(Object.keys(pkg(`apps/${app}`))).toEqual(expect.arrayContaining(['@826/core', '@826/ops-features']));
    });
});
