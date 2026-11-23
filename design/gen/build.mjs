// node design/gen/build.mjs [module,module…] — builds design/826-phase2.figcraft and per-frame PNGs.
// Modules run in order; system must run before any page that uses buttons or chips.
import { existsSync, writeFileSync, rmSync, mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createDoc } from './lib.mjs';

const HERE = new URL('.', import.meta.url).pathname;
const OUT = `${HERE}../`;
const ORDER = [
  ['cover', 'Cover & feedback map'],
  ['system', 'Design system'],
  ['admin', 'Admin website'],
  ['staff', 'Staff app'],
  ['customer', 'Customer app', 'Website'],
];
const only = process.argv[2]?.split(',');
const name = process.argv[3] ?? '826-phase2';

const d = createDoc();
const pages = [];
for (const [mod, ...modPages] of ORDER) {
  if (only && !only.includes(mod) && mod !== 'system') continue;
  if (!existsSync(`${HERE}${mod}.mjs`)) continue;
  const fn = (await import(`./${mod}.mjs`)).default;
  fn(d);
  if (!only || only.includes(mod)) pages.push(...modPages);
}
const steps = d.finish();
writeFileSync(`${OUT}${name}.run.json`, JSON.stringify(steps));
console.log(`${steps.length} steps`);

const run = (args) => execFileSync('figcraft.cli', args, { cwd: OUT, encoding: 'utf8', maxBuffer: 1 << 28 });
try {
  run(['run', `${name}.run.json`, '--out', `${name}.figcraft`]);
} catch (e) {
  const lines = String(e.stdout).trim().split('\n');
  console.error(lines.filter((l) => l.includes('"ok":false')).join('\n').slice(0, 3000) || String(e.stderr).slice(0, 3000));
  process.exit(1);
}
console.log(run(['info', `${name}.figcraft`]).split('\n')[0]);
if (process.env.NORENDER) process.exit(0);
for (const page of pages) {
  const dir = `${OUT}renders/${page.replace(/\W+/g, '-')}`;
  rmSync(dir, { recursive: true, force: true });
  mkdirSync(dir, { recursive: true });
  run(['export', `${name}.figcraft`, '--page', page, '--out', dir]);
}
console.log('rendered', pages.join(', '));
