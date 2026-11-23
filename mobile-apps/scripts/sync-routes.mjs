// expo-router only reads routes from inside an app's own folder, so each team app holds one re-export file per route of
// packages/ops-features/app. Run after adding or removing a route there:  node scripts/sync-routes.mjs owner sales operations system
import { mkdirSync, readdirSync, rmSync, writeFileSync } from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = path.join(root, 'packages/ops-features/app');
const walk = (dir) => readdirSync(dir, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(path.join(dir, e.name)) : [path.relative(source, path.join(dir, e.name))]));
const routes = walk(source);

for (const app of process.argv.slice(2)) {
    const target = path.join(root, 'apps', app, 'app');
    rmSync(target, { recursive: true, force: true });
    for (const route of routes) {
        const file = path.join(target, route);
        mkdirSync(path.dirname(file), { recursive: true });
        writeFileSync(file, `export { default } from '@826/ops-features/app/${route.replace(/\.tsx$/, '')}';\n`);
    }
    console.log(`${app}: ${routes.length} routes`);
}
