// FigCraft script builder for the 826 Phase 2 design file.
// Every helper pushes `figcraft-cli run` steps and returns a ref ({$ref}) to the layer it made.
import { readFileSync } from 'node:fs';

const ROOT = '/home/dranreb/Documents/Projects/826auto';
const HERO = `${ROOT}/vendor/blade-ui-kit/blade-heroicons/resources/svg`;

export const C = {
  page: '#060708',
  bg: '#0b0c0e',
  s2: '#131518',
  s3: '#1d2024',
  s4: '#262a2f',
  panelTop: '#17191c',
  panelBottom: '#111316',
  text: '#eef0f1',
  muted: '#9ca4a8',
  faint: '#6b7478',
  line: '#eef0f117',
  lineStrong: '#eef0f12e',
  accent: '#2ad3cb',
  onAccent: '#032120',
  accentSoft: '#2ad3cb1f',
  gold: '#cdb57a',
  goldSoft: '#cdb57a1f',
  danger: '#ff9e92',
  dangerSoft: '#ff9e921f',
  info: '#8ec2ff',
  infoSoft: '#8ec2ff1f',
  ok: '#7fdc9a',
  okSoft: '#7fdc9a1f',
  steel: '#8d969a',
  steelSoft: '#8d969a1f',
};

// Job statuses (no bays: a unit moves through these, the head detailer updates them).
export const STATUS = {
  Booked: [C.steel, C.steelSoft],
  Arrived: [C.info, C.infoSoft],
  'In progress': [C.accent, C.accentSoft],
  'Quality check': [C.gold, C.goldSoft],
  Ready: [C.ok, C.okSoft],
  Exited: [C.faint, '#6b74781f'],
  Backjob: [C.danger, C.dangerSoft],
  'Fully booked': [C.danger, C.dangerSoft],
  'Needs review': [C.gold, C.goldSoft],
  Active: [C.ok, C.okSoft],
  Expired: [C.faint, '#6b74781f'],
  Unread: [C.accent, C.accentSoft],
};
export const FLOW = ['Booked', 'Arrived', 'In progress', 'Quality check', 'Ready', 'Exited'];

// Car cutouts already in the 826 site (stand-ins until 826's own photos).
export const CARS = {
  bmw: 'bmw-7-series-side-black-600.webp',
  roma: 'ferrari-roma-side-red-600.webp',
  g63: 'g-class-g63-34-black-600.webp',
  lc300: 'land-cruiser-300-side-black-600.webp',
  lx600: 'lexus-lx600-34-silver-600.webp',
  mustang: 'mustang-dark-horse-34-blue-600.webp',
  p911: 'porsche-911-34-white-600.webp',
  rr: 'range-rover-side-white-600.webp',
};
export const MARK = `${ROOT}/public/brand/826-mark-480.webp`;

const STYLE = { 400: 'Regular', 500: 'Medium', 600: 'SemiBold', 700: 'Bold', 800: 'ExtraBold' };

// Scale every coordinate of a 24-unit heroicon path (arc rotation and flags stay; packed flags are split).
function scalePath(d, k) {
  const out = [];
  let i = 0;
  let cmd = '';
  let idx = 0;
  const num = /^-?(?:\d+\.?\d*|\.\d+)(?:e-?\d+)?/;
  while (i < d.length) {
    const ch = d[i];
    if (/[\s,]/.test(ch)) {
      i++;
      continue;
    }
    if (/[A-Za-z]/.test(ch)) {
      cmd = ch;
      idx = 0;
      out.push(ch);
      i++;
      continue;
    }
    const arc = cmd === 'A' || cmd === 'a';
    const slot = idx % 7;
    if (arc && (slot === 3 || slot === 4)) {
      out.push(ch);
      i++;
      idx++;
      continue;
    }
    const m = d.slice(i).match(num);
    if (!m) throw new Error(`bad path at ${i}: ${d.slice(i, i + 12)}`);
    let v = parseFloat(m[0]);
    if (!(arc && slot === 2)) v *= k;
    out.push(String(+v.toFixed(3)));
    i += m[0].length;
    idx++;
  }
  return out.join(' ');
}

function iconPath(name, size) {
  const svg = readFileSync(`${HERO}/o-${name}.svg`, 'utf8');
  const ds = [...svg.matchAll(/ d="([^"]+)"/g)].map((m) => m[1]);
  if (!ds.length) throw new Error(`icon ${name}: no path`);
  return ds.map((d) => scalePath(d, size / 24)).join(' ');
}

export function createDoc() {
  const steps = [];
  const deferred = [];
  const screens = {};
  const comps = {};
  let n = 0;
  let pages = 0;
  let grid = null;

  const ref = (k) => ({ $ref: k });
  const uid = (p = 'n') => `${p}${++n}`;

  function cmd(command, params, as) {
    const s = { command, params };
    if (as) s.as = as;
    steps.push(s);
    return as ? ref(as) : null;
  }

  // ---------- pages and screens ----------
  // device: prototype device for presenting this page ('iPhone 17', 'Desktop' = 1440×1024, 'None').
  // responsive: frames stretch to whatever device is picked when presenting (auto layout reflows), so no side gaps.
  function page(name, { cols = 4, w = 390, h = 844, gapX = 120, gapY = 200, device = 'None', responsive = false } = {}) {
    if (pages === 0) cmd('page.rename', { name });
    else cmd('page.add', { name });
    pages++;
    cmd('page.setBackground', { color: C.page });
    cmd('proto.settings', { device, responsive, background: C.page });
    grid = { cols, w, h, gapX, gapY, i: 0, rowH: 0, y: 0 };
  }

  // A top-level frame with a label above it. `covers` lists feedback item numbers.
  function screen(code, title, { w, h, fill = C.bg, covers = [], note = '' } = {}) {
    w ??= grid.w;
    h ??= grid.h;
    const col = grid.i % grid.cols;
    if (col === 0 && grid.i > 0) {
      grid.y += grid.rowH + grid.gapY;
      grid.rowH = 0;
    }
    const x = col * (grid.w + grid.gapX);
    const y = grid.y;
    grid.rowH = Math.max(grid.rowH, h);
    grid.i++;
    const label = `${code}  ${title}`;
    cmd('text.create', { text: label, x, y: y - 92, name: `Label ${code}`, props: font('display', 26, 800, C.text) });
    const sub = [covers.length ? `Feedback ${covers.map((c) => `#${c}`).join(' ')}` : '', note].filter(Boolean).join('  ·  ');
    if (sub) cmd('text.create', { text: sub, x, y: y - 44, width: w, name: `Covers ${code}`, props: { ...font('body', 15, 500, C.muted), textAutoResize: 'height' } });
    const as = `scr_${code}`;
    cmd('frame.create', { x, y, width: w, height: h, name: `${code} ${title}`, props: { fill, clip: true } }, as);
    screens[code] = ref(as);
    return ref(as);
  }

  // ---------- primitives ----------
  function font(kind, size, weight, color) {
    if (kind === 'display') {
      const st = { 600: 'SemiBold Italic', 700: 'Bold Italic', 800: 'ExtraBold Italic' }[weight] ?? 'ExtraBold Italic';
      return { fontFamily: 'Archivo Expanded', fontStyle: st, fontSize: size, fill: color };
    }
    return { fontFamily: 'Archivo', fontStyle: STYLE[weight] ?? 'Regular', fontSize: size, fill: color };
  }

  /**
   * frame(parent, o): o = {name, dir:'h'|'v'|'grid', gap, pad, fill, fills, stroke, sw, radius,
   *   w (number|'fill'|'hug'), h (number|'fill'|'hug'), x, y, align (main: min|center|max|spaceBetween),
   *   cross (min|center|max|baseline), clip, shadow, opacity, wrap, abs, cols/rows (grid)}
   * Auto-layout frames hug by default; give w/h to fix a size or 'fill' to stretch in the parent.
   */
  function frame(parent, o = {}) {
    const props = {};
    const p = { name: o.name ?? (o.dir ? 'Stack' : 'Frame'), x: o.x ?? 0, y: o.y ?? 0, props };
    if (parent) p.parent = parent;
    if (o.dir) {
      props.layout = { h: 'horizontal', v: 'vertical', grid: 'grid' }[o.dir];
      if (o.gap !== undefined) props.gap = o.gap;
      if (o.crossGap !== undefined) props.crossGap = o.crossGap;
      if (o.pad !== undefined) props.padding = o.pad;
      if (o.align) props.mainAlign = o.align;
      if (o.cross) props.crossAlign = o.cross;
      if (o.wrap) props.wrap = true;
      if (o.cols) props.gridColumns = o.cols;
      if (o.rows) props.gridRows = o.rows;
      if (o.colTracks) props.columnTracks = o.colTracks;
    }
    props.fill = o.fill ?? 'none';
    if (o.fills) {
      delete props.fill;
      props.fills = o.fills;
    }
    if (o.stroke) {
      props.stroke = o.stroke;
      props.strokeWeight = o.sw ?? 1;
      props.strokeAlign = 'inside';
      if (o.sides) props.strokeSides = o.sides;
    }
    if (o.radius !== undefined) props.cornerRadius = o.radius;
    if (o.clip) props.clip = true;
    if (o.opacity !== undefined) props.opacity = o.opacity;
    if (o.shadow) props.effects = [{ type: 'dropShadow', color: { r: 0, g: 0, b: 0, a: 0.55 }, offset: [0, 18], radius: 40, spread: -18 }];
    if (o.abs) props.absolute = true;
    for (const [k, axis] of [['w', 'H'], ['h', 'V']]) {
      const v = o[k];
      if (typeof v === 'number') {
        p[k === 'w' ? 'width' : 'height'] = v;
        if (o.dir) props[`sizing${axis}`] = 'fixed';
      } else if (v === 'fill') props[`sizing${axis}`] = 'fill';
      else if (o.dir) props[`sizing${axis}`] = 'hug';
    }
    if (!o.dir && typeof o.w !== 'number') p.width = 10;
    if (!o.dir && typeof o.h !== 'number') p.height = 10;
    return cmd('frame.create', p, uid());
  }

  const row = (parent, o = {}) => frame(parent, { gap: 8, cross: 'center', ...o, dir: 'h' });
  const col = (parent, o = {}) => frame(parent, { gap: 8, ...o, dir: 'v' });

  /**
   * text(parent, s, o): o = {size=14, weight=400 (400..800), display (Archivo Expanded italic caps),
   *   color, w (number|'fill'), align, upper, ls (letterSpacing), lh (lineHeight), name, maxLines}
   */
  function text(parent, s, o = {}) {
    const size = o.size ?? 14;
    const props = font(o.display ? 'display' : 'body', size, o.weight ?? (o.display ? 800 : 400), o.color ?? C.text);
    if (o.upper || o.display) props.textCase = 'upper';
    if (o.ls !== undefined) props.letterSpacing = o.ls;
    if (o.lh !== undefined) props.lineHeight = o.lh;
    if (o.align) props.textAlign = o.align;
    if (o.maxLines) {
      props.maxLines = o.maxLines;
    }
    const p = { text: String(s), x: o.x ?? 0, y: o.y ?? 0, props };
    if (o.name) p.name = o.name;
    if (parent) p.parent = parent;
    if (typeof o.w === 'number') {
      p.width = o.w;
      props.textAutoResize = o.maxLines ? 'truncate' : 'height';
    } else if (o.w === 'fill') {
      props.sizingH = 'fill';
      props.textAutoResize = o.maxLines ? 'truncate' : 'height';
    }
    return cmd('text.create', p, uid());
  }

  function rect(parent, { w, h, fill = C.s3, radius = 0, name = 'Rect', x = 0, y = 0, stroke, abs } = {}) {
    const props = { fill, cornerRadius: radius };
    if (stroke) Object.assign(props, { stroke, strokeWeight: 1, strokeAlign: 'inside' });
    if (abs) props.absolute = true;
    return cmd('node.create', { type: 'rectangle', parent, x, y, width: w, height: h, name, props }, uid());
  }

  function ellipse(parent, { size, fill = C.accent, name = 'Dot', x = 0, y = 0, stroke } = {}) {
    const props = { fill };
    if (stroke) Object.assign(props, { stroke, strokeWeight: 2, strokeAlign: 'inside' });
    return cmd('node.create', { type: 'ellipse', parent, x, y, width: size, height: size, name, props }, uid());
  }

  function img(parent, path, { w, h, mode = 'fit', name } = {}) {
    const p = { path, width: w, height: h, mode, parent };
    if (name) p.name = name;
    return cmd('image.place', p, uid());
  }

  const car = (parent, key, { w = 140, h = 52 } = {}) => img(parent, `${ROOT}/public/cars/${CARS[key]}`, { w, h, name: `Car ${key}` });

  /** icon(parent, heroiconName, {size=20, color}) — any outline heroicon name, e.g. 'calendar', 'chat-bubble-left-right'. */
  function icon(parent, name, { size = 20, color = C.muted } = {}) {
    const box = frame(parent, { w: size, h: size, name: `Icon ${name}` });
    cmd('vector.create', {
      path: iconPath(name, size),
      x: 0,
      y: 0,
      parent: box,
      name,
      props: { fill: 'none', stroke: color, strokeWeight: size >= 22 ? 1.6 : 1.5, strokeCap: 'round', strokeJoin: 'round' },
    });
    return box;
  }

  /** Vector line chart: values → polyline inside a w×h box (optional teal area under it). */
  function spark(parent, values, { w = 120, h = 36, color = C.accent, area = false, name = 'Sparkline' } = {}) {
    const box = frame(parent, { w, h, name });
    const max = Math.max(...values);
    const min = Math.min(...values);
    const pts = values.map((v, i) => [(i / (values.length - 1)) * w, h - 2 - ((v - min) / (max - min || 1)) * (h - 4)]);
    const d = pts.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)} ${y.toFixed(1)}`).join(' ');
    if (area) {
      cmd('vector.create', {
        path: `${d} L${w} ${h} L0 ${h} Z`,
        x: 0,
        y: 0,
        parent: box,
        name: 'Area',
        props: { fills: [{ type: 'linearGradient', stops: [`${color}40`, `${color}00`] }], stroke: 'none' },
      });
    }
    cmd('vector.create', { path: d, x: 0, y: 0, parent: box, name: 'Line', props: { fill: 'none', stroke: color, strokeWeight: 2, strokeJoin: 'round', strokeCap: 'round' } });
    return box;
  }

  /** Vertical bars: values → bottom-aligned bars with optional labels under them. */
  function bars(parent, values, { w = 400, h = 160, color = C.accent, labels, highlight = -1, name = 'Bars' } = {}) {
    const wrap = col(parent, { gap: 8, w, name });
    const area = row(wrap, { w, h, gap: 10, cross: 'max', name: 'Plot' });
    const max = Math.max(...values);
    const bw = (w - 10 * (values.length - 1)) / values.length;
    values.forEach((v, i) => rect(area, { w: bw, h: Math.max(4, (v / max) * h), fill: highlight < 0 || i === highlight ? color : `${color}55`, radius: [4, 4, 0, 0], name: `Bar ${i + 1}` }));
    if (labels) {
      const lr = row(wrap, { w, gap: 10, name: 'Labels' });
      labels.forEach((l) => text(lr, l, { size: 11, color: C.faint, w: bw, align: 'center' }));
    }
    return wrap;
  }

  // ---------- components (made on the Design system page) ----------
  function registerComponent(name, nodeRef) {
    const as = `comp_${name.replace(/\W+/g, '_')}`;
    cmd('component.create', { ids: [nodeRef] }, as);
    comps[name] = ref(as);
    return ref(as);
  }

  function instance(name, parent, overrides = {}) {
    const as = uid('i');
    cmd('instance.create', { component: comps[name], parent }, as);
    for (const [layer, value] of Object.entries(overrides)) {
      const f = uid('f');
      cmd('node.find', { root: ref(as), name: layer, limit: 1 }, f);
      cmd('text.set', { id: { $ref: f, field: 'ids/0' }, text: String(value) });
    }
    return ref(as);
  }

  // ---------- composite UI ----------
  /** Button instance: kind = primary | secondary | ghost | danger. Small: size 'sm'. */
  function button(parent, label, kind = 'primary', { size = 'md' } = {}) {
    return instance(`Button/${kind}${size === 'sm' ? '/sm' : ''}`, parent, { 'Button label': label });
  }

  /** Status chip instance (keys of STATUS). */
  function chip(parent, status) {
    if (!STATUS[status]) throw new Error(`chip: unknown status ${status}`);
    return instance(`Chip/${status}`, parent);
  }

  /** Plain tag: neutral pill with text (role, branch, service). */
  function tag(parent, label, { color = C.muted, bg = C.s3, size = 12, iconName } = {}) {
    const t = row(parent, { pad: [5, 10, 5, 10], radius: 999, fill: bg, gap: 6, name: `Tag ${label}` });
    if (iconName) icon(t, iconName, { size: 14, color });
    text(t, label, { size, weight: 600, color });
    return t;
  }

  function avatar(parent, initials, { size = 36, color = C.accent } = {}) {
    const a = frame(parent, { dir: 'h', w: size, h: size, radius: 999, fill: `${color}26`, align: 'center', cross: 'center', name: `Avatar ${initials}` });
    text(a, initials, { size: Math.round(size * 0.36), weight: 700, color });
    return a;
  }

  /** Card panel; returns {card, body}. `title` adds a header row, `action` a ghost link on the right. */
  function card(parent, { title, sub, action, w = 'fill', h, pad = 20, gap = 14, name, fill, actionTo } = {}) {
    const c = col(parent, {
      w,
      h,
      pad,
      gap,
      radius: 16,
      fills: fill ? undefined : [{ type: 'linearGradient', stops: [C.panelTop, C.panelBottom] }],
      fill,
      stroke: C.line,
      name: name ?? `Card ${title ?? ''}`.trim(),
    });
    if (title) {
      const hr = row(c, { w: 'fill', align: 'spaceBetween', name: 'Card header' });
      const tl = col(hr, { gap: 2 });
      text(tl, title, { size: 15, weight: 700 });
      if (sub) text(tl, sub, { size: 12, color: C.muted });
      if (action) {
        const a = text(hr, action, { size: 13, weight: 600, color: C.accent });
        if (actionTo) link(a, actionTo);
      }
    }
    return c;
  }

  function kpi(parent, label, value, { delta, deltaUp = true, w = 'fill', spark: sp, iconName } = {}) {
    const c = card(parent, { w, gap: 10, pad: 18, name: `KPI ${label}` });
    const top = row(c, { w: 'fill', align: 'spaceBetween' });
    text(top, label, { size: 12, weight: 600, color: C.muted, upper: true, ls: '6%' });
    if (iconName) icon(top, iconName, { size: 18, color: C.faint });
    const mid = row(c, { w: 'fill', align: 'spaceBetween', cross: 'max' });
    text(mid, value, { size: 28, weight: 700 });
    if (sp) spark(mid, sp, { w: 72, h: 26 });
    if (delta) text(c, delta, { size: 12, weight: 600, color: deltaUp ? C.ok : C.danger, w: 'fill' });
    return c;
  }

  /** Form field: label + box with value/placeholder. kind 'select' adds a chevron. */
  function field(parent, label, value, { w = 'fill', placeholder = false, kind = 'input', iconName, hint, h = 46 } = {}) {
    const f = col(parent, { w, gap: 6, name: `Field ${label}` });
    if (label) text(f, label, { size: 12, weight: 600, color: C.muted });
    const box = row(f, { w: 'fill', h, pad: [0, 14, 0, 14], radius: 10, fill: C.s2, stroke: C.lineStrong, gap: 10, name: 'Box' });
    if (iconName) icon(box, iconName, { size: 18, color: C.faint });
    text(box, value, { size: 14, color: placeholder ? C.faint : C.text, w: 'fill', maxLines: 1 });
    if (kind === 'select') icon(box, 'chevron-down', { size: 16, color: C.muted });
    if (kind === 'date') icon(box, 'calendar', { size: 16, color: C.muted });
    if (hint) text(f, hint, { size: 12, color: C.faint, w: 'fill' });
    return f;
  }

  /** Underline tabs; items: strings or {label, count}. */
  function tabs(parent, items, active = 0, { w = 'fill', size = 14 } = {}) {
    const t = row(parent, { w, gap: 24, cross: 'max', stroke: C.line, sides: [0, 0, 1, 0], name: 'Tabs' });
    items.forEach((it, i) => {
      const label = typeof it === 'string' ? it : it.label;
      const on = i === active;
      const cell = col(t, { gap: 10, name: `Tab ${label}` });
      const lr = row(cell, { gap: 6, h: 24 });
      text(lr, label, { size, weight: on ? 700 : 500, color: on ? C.text : C.muted });
      if (typeof it === 'object' && it.count !== undefined) tag(lr, String(it.count), { size: 11, color: on ? C.onAccent : C.muted, bg: on ? C.accent : C.s3 });
      fill(rect(cell, { w: 10, h: 2, fill: on ? C.accent : 'none', name: 'Underline' }));
    });
    return t;
  }

  /** Segmented control (pill group). */
  function segmented(parent, items, active = 0) {
    const s = row(parent, { pad: 4, gap: 4, radius: 12, fill: C.s2, stroke: C.line, name: 'Segmented' });
    items.forEach((l, i) => {
      const it = row(s, { pad: [8, 14, 8, 14], radius: 9, fill: i === active ? C.s4 : 'none', name: `Seg ${l}` });
      text(it, l, { size: 13, weight: i === active ? 700 : 500, color: i === active ? C.text : C.muted });
    });
    return s;
  }

  /**
   * table(parent, cols, rows, o): cols = [{label, w (number|'fill'), align}],
   * rows = [[cell, ...]] where cell is a string or (cellFrame) => void.
   */
  function table(parent, cols, rows, { w = 'fill', rowH = 56, zebra = false, onRow } = {}) {
    const t = col(parent, { w, gap: 0, name: 'Table' });
    const head = row(t, { w: 'fill', h: 40, gap: 16, pad: [0, 16, 0, 16], stroke: C.line, sides: [0, 0, 1, 0], name: 'Head' });
    cols.forEach((c) => {
      const cell = row(head, { w: c.w, align: c.align === 'right' ? 'max' : 'min', name: `H ${c.label}` });
      text(cell, c.label, { size: 11, weight: 600, color: C.faint, upper: true, ls: '6%' });
    });
    rows.forEach((r, ri) => {
      const rr = row(t, { w: 'fill', h: rowH, gap: 16, pad: [0, 16, 0, 16], stroke: C.line, sides: [0, 0, 1, 0], fill: zebra && ri % 2 ? '#ffffff05' : 'none', name: `Row ${ri + 1}` });
      cols.forEach((c, ci) => {
        const cell = row(rr, { w: c.w, gap: 10, align: c.align === 'right' ? 'max' : 'min', name: `C ${c.label}` });
        const v = r[ci];
        if (typeof v === 'function') v(cell);
        else text(cell, v, { size: 14, color: ci === 0 ? C.text : C.muted, weight: ci === 0 ? 600 : 400, maxLines: 1, w: typeof c.w === 'number' ? undefined : 'fill' });
      });
      if (onRow) onRow(rr, ri);
    });
    return t;
  }

  /** Unit = year + model + type (no size picker). car: CARS key. */
  function unit(parent, { car: carKey, year, model, type, plate, w = 'fill', compact = false } = {}) {
    const u = row(parent, { w, gap: 14, name: `Unit ${model}` });
    const pic = frame(u, { dir: 'h', w: compact ? 84 : 112, h: compact ? 44 : 56, radius: 10, fill: C.s2, align: 'center', cross: 'center', name: 'Car tile', clip: true });
    if (carKey) car(pic, carKey, { w: compact ? 80 : 108, h: compact ? 36 : 46 });
    const t = col(u, { gap: 3, w: 'fill' });
    text(t, `${year} ${model}`, { size: compact ? 14 : 15, weight: 700, w: 'fill', maxLines: 1 });
    text(t, [type, plate].filter(Boolean).join('  ·  '), { size: 12, color: C.muted, w: 'fill', maxLines: 1 });
    return u;
  }

  /** Horizontal status stepper over FLOW; current = index. */
  function stepper(parent, current, { w = 'fill', steps: list = FLOW, times = [] } = {}) {
    const s = row(parent, { w, gap: 0, cross: 'min', name: 'Status stepper' });
    list.forEach((label, i) => {
      const done = i < current;
      const on = i === current;
      const c = col(s, { w: 'fill', gap: 8, name: `Step ${label}` });
      const line = row(c, { w: 'fill', gap: 0 });
      ellipse(line, { size: 14, fill: done || on ? C.accent : C.s3, stroke: on ? '#2ad3cb55' : undefined });
      if (i < list.length - 1) {
        const r = rect(line, { w: 10, h: 2, fill: done ? C.accent : C.s4 });
        cmd('node.set', { ids: [r], props: { sizingH: 'fill' } });
      }
      text(c, label, { size: 12, weight: on ? 700 : 500, color: on ? C.text : done ? C.muted : C.faint });
      if (times[i]) text(c, times[i], { size: 11, color: C.faint });
    });
    return s;
  }

  function divider(parent, { color = C.line } = {}) {
    const r = rect(parent, { w: 10, h: 1, fill: color, name: 'Divider' });
    cmd('node.set', { ids: [r], props: { sizingH: 'fill' } });
    return r;
  }

  function sample(parent) {
    return tag(parent, 'Sample data', { color: C.gold, bg: C.goldSoft, size: 11 });
  }

  /** Grows a layer to fill its auto-layout parent (axis 'h', 'v' or 'both'). */
  function fill(node, axis = 'h') {
    const props = {};
    if (axis !== 'v') props.sizingH = 'fill';
    if (axis !== 'h') props.sizingV = 'fill';
    cmd('node.set', { ids: [node], props });
    return node;
  }

  function set(node, props) {
    cmd('node.set', { ids: [node], props });
    return node;
  }

  // ---------- prototype ----------
  function link(fromRef, toCode) {
    deferred.push(() => {
      if (!screens[toCode]) return console.warn(`link skipped: no screen ${toCode}`);
      cmd('proto.interaction.add', { id: fromRef, dest: screens[toCode], transition: { type: 'dissolve' }, durationMs: 180 });
    });
  }
  function flow(code, name) {
    deferred.push(() => cmd('proto.flow.add', { start: screens[code], name }));
  }

  // ---------- shells ----------
  const ADMIN_NAV = [
    ['Overview', [['A1', 'Dashboard', 'squares-2x2']]],
    ['Operations', [['A2', 'Operations board', 'queue-list'], ['A8', 'Availability', 'calendar-days'], ['A9', 'Unit review', 'clipboard-document-check']]],
    ['Sales', [['A4', 'Inbox', 'chat-bubble-left-right'], ['A5', 'Customers', 'user-group'], ['A10', 'New transaction', 'document-plus']]],
    ['Aftercare', [['A7', 'Warranty checker', 'shield-check'], ['A14', 'Referrals', 'gift'], ['A13', 'Media & reviews', 'photo']]],
    ['Admin', [['A11', 'Users & roles', 'key']]],
  ];

  /**
   * adminShell(code, title, {active, sub, covers, note, h, actions:[label|{label,kind}], branch})
   * → content ref (vertical stack, pad 28, gap 20, fills the rest of the 1440-wide screen).
   */
  function adminShell(code, title, { active = code, sub, covers, note, h = 960, actions = [], branch = 'All branches' } = {}) {
    const s = screen(code, title, { w: 1440, h, covers, note });
    set(s, { layout: 'horizontal', gap: 0 });
    const side = col(s, { w: 256, h: 'fill', pad: [24, 16, 20, 16], gap: 22, fill: C.s2, stroke: C.line, sides: [0, 1, 0, 0], name: 'Sidebar' });
    const brand = row(side, { gap: 12, pad: [0, 8, 6, 8] });
    img(brand, MARK, { w: 44, h: 30, name: '826 mark' });
    const bt = col(brand, { gap: 1 });
    text(bt, '826 Auto', { size: 14, weight: 800 });
    text(bt, 'Aesthetic & Protection', { size: 11, color: C.muted });
    const bsel = row(side, { w: 'fill', pad: [10, 12, 10, 12], radius: 10, fill: C.s3, gap: 10, name: 'Branch switcher' });
    icon(bsel, 'map-pin', { size: 16, color: C.accent });
    text(bsel, branch, { size: 13, weight: 600, w: 'fill' });
    icon(bsel, 'chevron-up-down', { size: 16, color: C.muted });
    for (const [group, items] of ADMIN_NAV) {
      const g = col(side, { w: 'fill', gap: 2, name: `Nav ${group}` });
      text(g, group, { size: 11, weight: 600, color: C.faint, upper: true, ls: '8%', name: 'Group' });
      for (const [to, label, ic] of items) {
        const on = to === active;
        const it = row(g, { w: 'fill', pad: [9, 10, 9, 10], radius: 9, gap: 12, fill: on ? C.accentSoft : 'none', name: `Nav ${label}` });
        icon(it, ic, { size: 18, color: on ? C.accent : C.muted });
        text(it, label, { size: 14, weight: on ? 700 : 500, color: on ? C.text : C.muted, w: 'fill' });
        if (to === 'A4') tag(it, '12', { size: 11, color: C.onAccent, bg: C.accent });
        if (to === 'A9') tag(it, '3', { size: 11, color: C.gold, bg: C.goldSoft });
        if (!on) link(it, to);
      }
    }
    const spacer = col(side, { h: 'fill', w: 'fill', name: 'Spacer' });
    void spacer;
    const me = row(side, { w: 'fill', pad: [12, 10, 4, 10], gap: 10, stroke: C.line, sides: [1, 0, 0, 0], name: 'Signed in' });
    avatar(me, 'OW', { size: 34, color: C.gold });
    const mt = col(me, { gap: 1, w: 'fill' });
    text(mt, 'Owner', { size: 13, weight: 700 });
    text(mt, 'Super admin · all branches', { size: 11, color: C.muted });
    icon(me, 'shield-check', { size: 16, color: C.ok });

    const main = col(s, { w: 'fill', h: 'fill', gap: 0, name: 'Main' });
    const top = row(main, { w: 'fill', h: 76, pad: [0, 28, 0, 28], gap: 16, stroke: C.line, sides: [0, 0, 1, 0], name: 'Top bar' });
    const tt = col(top, { gap: 3, w: 'fill' });
    text(tt, title, { size: 22, display: true });
    if (sub) text(tt, sub, { size: 13, color: C.muted });
    sample(top);
    const search = row(top, { w: 240, pad: [9, 12, 9, 12], radius: 10, fill: C.s2, stroke: C.line, gap: 8, name: 'Search' });
    icon(search, 'magnifying-glass', { size: 16, color: C.faint });
    text(search, 'Search plate, name, booking', { size: 13, color: C.faint });
    const bell = frame(top, { dir: 'h', w: 38, h: 38, radius: 10, fill: C.s2, stroke: C.line, align: 'center', cross: 'center', name: 'Notifications' });
    icon(bell, 'bell', { size: 18, color: C.muted });
    for (const a of actions) {
      const { label, kind = 'primary' } = typeof a === 'string' ? { label: a } : a;
      button(top, label, kind);
    }
    return col(main, { w: 'fill', h: 'fill', pad: 28, gap: 20, clip: true, name: 'Content' });
  }

  function statusBar(parent, { color = C.text } = {}) {
    const sb = row(parent, { w: 'fill', h: 48, pad: [0, 28, 0, 32], align: 'spaceBetween', name: 'Status bar' });
    text(sb, '9:41', { size: 15, weight: 700, color });
    const r = row(sb, { gap: 6 });
    icon(r, 'signal', { size: 16, color });
    icon(r, 'wifi', { size: 16, color });
    rect(r, { w: 24, h: 12, radius: 3, fill: color, name: 'Battery' });
    return sb;
  }

  /**
   * mobileShell(code, title, {tabs:[{label, icon, to}], active, sub, back, right (icon name), covers, note, role, scroll})
   * → content ref (vertical, pad [4,20,20,20], gap 14). Tab bar items link to their `to` screens.
   */
  function mobileShell(code, title, { tabs: tabItems = [], active = 0, sub, back, right, covers, note, role, h = 844, bigTitle = true } = {}) {
    const s = screen(code, title, { w: 390, h, covers, note });
    set(s, { layout: 'vertical', gap: 0, cornerRadius: 44 });
    statusBar(s);
    const head = col(s, { w: 'fill', pad: [6, 20, 10, 20], gap: 6, name: 'Header' });
    const hr = row(head, { w: 'fill', align: 'spaceBetween', h: 36 });
    if (back) {
      const b = row(hr, { gap: 4 });
      icon(b, 'chevron-left', { size: 22, color: C.accent });
      text(b, back.label ?? 'Back', { size: 15, weight: 600, color: C.accent });
      if (back.to) link(b, back.to);
    } else if (role) {
      tag(hr, role, { color: C.accent, bg: C.accentSoft, iconName: 'identification' });
    } else {
      const b = row(hr, { gap: 8 });
      img(b, MARK, { w: 40, h: 27, name: '826 mark' });
    }
    if (right) {
      const r = frame(hr, { dir: 'h', w: 38, h: 38, radius: 999, fill: C.s2, align: 'center', cross: 'center', name: 'Header action' });
      icon(r, right, { size: 20, color: C.text });
    }
    if (bigTitle) text(head, title, { size: 26, display: true, w: 'fill' });
    if (sub) text(head, sub, { size: 14, color: C.muted, w: 'fill' });
    const content = col(s, { w: 'fill', h: 'fill', pad: [6, 20, 20, 20], gap: 14, clip: true, name: 'Content' });
    if (tabItems.length) {
      const bar = row(s, { w: 'fill', h: 86, pad: [10, 12, 26, 12], gap: 0, fill: C.s2, stroke: C.line, sides: [1, 0, 0, 0], cross: 'min', name: 'Tab bar' });
      tabItems.forEach((t, i) => {
        const on = i === active;
        const it = col(bar, { w: 'fill', gap: 4, cross: 'center', name: `Tab ${t.label}` });
        icon(it, t.icon, { size: 24, color: on ? C.accent : C.faint });
        text(it, t.label, { size: 11, weight: on ? 700 : 500, color: on ? C.accent : C.faint });
        if (!on && t.to) link(it, t.to);
      });
    }
    return content;
  }

  function finish() {
    for (const f of deferred) f();
    return steps;
  }

  return {
    C, STATUS, FLOW, CARS, MARK, steps, screens, comps,
    cmd, ref, uid, page, screen, frame, row, col, text, rect, ellipse, img, car, icon, spark, bars,
    registerComponent, instance, button, chip, tag, avatar, card, kpi, field, tabs, segmented, table, unit,
    stepper, divider, sample, fill, set, link, flow, adminShell, mobileShell, statusBar, finish,
  };
}
