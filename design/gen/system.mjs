// Page 1: design system — colour variables, text styles, components used by every other page.
export default function system(d) {
  const { C, STATUS, FLOW } = d;
  d.page('Design system', { cols: 2, w: 1200, h: 900, gapX: 160, gapY: 220 });

  // Colour variables (dark is the 826 default).
  d.cmd('variable.createCollection', { name: '826 colours', modes: ['Dark'] }, 'vc');
  const swatches = [
    ['surface/page', C.page], ['surface/base', C.bg], ['surface/raised', C.s2], ['surface/high', C.s3], ['surface/top', C.s4],
    ['text/primary', C.text], ['text/muted', C.muted], ['text/faint', C.faint], ['line/default', C.line], ['line/strong', C.lineStrong],
    ['brand/teal', C.accent], ['brand/on-teal', C.onAccent], ['brand/gold', C.gold],
    ['status/info', C.info], ['status/ok', C.ok], ['status/danger', C.danger], ['status/steel', C.steel],
  ];
  for (const [name, value] of swatches) d.cmd('variable.create', { collection: { $ref: 'vc' }, name, type: 'color', value });

  // Board: colours
  const colors = d.screen('DS1', 'Colour', { w: 1200, h: 900, covers: [25], note: 'Same tokens as the live 826 site (dark default)' });
  d.set(colors, { layout: 'vertical', padding: 48, gap: 28 });
  d.text(colors, 'Colour', { size: 34, display: true });
  d.text(colors, 'Graphite surfaces from the detailing bay, one teal for actions, gold only for highlights and sample labels.', { size: 15, color: C.muted, w: 'fill' });
  const grid = d.frame(colors, { dir: 'h', gap: 20, wrap: true, crossGap: 20, w: 'fill', name: 'Swatches' });
  for (const [name, value] of swatches) {
    const sw = d.col(grid, { w: 196, gap: 10, name: `Swatch ${name}` });
    d.frame(sw, { w: 196, h: 96, radius: 14, fill: value, stroke: C.lineStrong, name: 'Chip' });
    d.text(sw, name, { size: 13, weight: 700 });
    d.text(sw, value.toUpperCase(), { size: 12, color: C.muted });
  }

  // Board: type
  const type = d.screen('DS2', 'Type', { w: 1200, h: 900 });
  d.set(type, { layout: 'vertical', padding: 48, gap: 26 });
  d.text(type, 'Type', { size: 34, display: true });
  const specs = [
    ['Display / XL', 'Detailing, tracked.', { size: 44, display: true }],
    ['Display / L', 'Operations board', { size: 28, display: true }],
    ['Display / M', 'Today at Cainta', { size: 20, display: true }],
    ['Title', 'Ceramic coating · 2023 BMW 7 Series', { size: 18, weight: 700 }],
    ['Body', 'Head detailers update each unit as it moves. Sales gets a heads-up before the next unit can come in.', { size: 15, w: 900, lh: '150%', color: C.muted }],
    ['Label', 'ARRIVED 9:12 AM', { size: 12, weight: 600, upper: true, ls: '6%', color: C.muted }],
    ['Number', '₱ 1,284,500', { size: 30, weight: 700 }],
  ];
  for (const [name, sample, o] of specs) {
    const r = d.row(type, { gap: 32, w: 'fill', cross: 'baseline' });
    d.text(r, name, { size: 12, weight: 600, color: C.faint, w: 140 });
    const t = d.text(r, sample, o);
    d.cmd('style.create', { type: 'text', name, from: t });
  }
  d.text(type, 'Archivo Expanded ExtraBold Italic for headings (all caps), Archivo for everything else.', { size: 13, color: C.faint });

  // Board: components
  const comps = d.screen('DS3', 'Components', { w: 1200, h: 900 });
  d.set(comps, { layout: 'vertical', padding: 48, gap: 26 });
  d.text(comps, 'Components', { size: 34, display: true });

  d.text(comps, 'Buttons', { size: 13, weight: 600, color: C.faint, upper: true, ls: '8%' });
  const br = d.row(comps, { gap: 16, wrap: true, w: 'fill', crossGap: 16 });
  const kinds = {
    primary: { fill: C.accent, color: C.onAccent },
    secondary: { fill: C.s3, color: C.text, stroke: C.lineStrong },
    ghost: { fill: 'none', color: C.accent },
    danger: { fill: C.dangerSoft, color: C.danger },
  };
  for (const sm of [false, true]) {
    for (const [kind, k] of Object.entries(kinds)) {
      const b = d.row(br, { pad: sm ? [7, 12, 7, 12] : [11, 18, 11, 18], radius: sm ? 8 : 10, fill: k.fill, stroke: k.stroke, align: 'center', name: `Button/${kind}${sm ? '/sm' : ''}` });
      d.text(b, kind === 'primary' ? 'Book now' : kind === 'danger' ? 'Cancel booking' : kind === 'ghost' ? 'View all' : 'Message', { size: sm ? 13 : 14, weight: 600, color: k.color, name: 'Button label' });
      d.registerComponent(`Button/${kind}${sm ? '/sm' : ''}`, b);
    }
  }

  d.text(comps, 'Status chips', { size: 13, weight: 600, color: C.faint, upper: true, ls: '8%' });
  const cr = d.row(comps, { gap: 12, wrap: true, w: 'fill', crossGap: 12 });
  for (const [status, [fg, bg]] of Object.entries(STATUS)) {
    const c = d.row(cr, { pad: [5, 10, 5, 9], radius: 999, fill: bg, gap: 6, name: `Chip/${status}` });
    d.ellipse(c, { size: 6, fill: fg });
    d.text(c, status, { size: 12, weight: 600, color: fg, name: 'Chip label' });
    d.registerComponent(`Chip/${status}`, c);
  }

  d.text(comps, 'Fields, tabs, unit, job progress', { size: 13, weight: 600, color: C.faint, upper: true, ls: '8%' });
  const fr = d.row(comps, { gap: 20, w: 'fill', cross: 'min' });
  d.field(fr, 'Unit year', '2023', { w: 160, kind: 'select' });
  d.field(fr, 'Model', 'BMW 7 Series', { w: 260 });
  d.field(fr, 'Type of unit', 'Sedan', { w: 200, kind: 'select' });
  d.field(fr, 'Referral code', 'e.g. MIGUEL-826', { w: 240, placeholder: true, iconName: 'gift' });
  d.tabs(comps, [{ label: 'Cainta', count: 5 }, { label: 'Quezon City', count: 4 }, { label: 'San Mateo', count: 3 }, 'Website', 'Instagram'], 0);
  const ur = d.row(comps, { gap: 40, w: 'fill' });
  d.unit(ur, { car: 'bmw', year: 2023, model: 'BMW 7 Series', type: 'Sedan', plate: 'NDA 4821', w: 380 });
  d.stepper(ur, 2, { w: 'fill' });
  void FLOW;

  // Board: patterns (cards, KPI, table, avatars, icons)
  const pat = d.screen('DS4', 'Patterns', { w: 1200, h: 900 });
  d.set(pat, { layout: 'vertical', padding: 48, gap: 26 });
  d.text(pat, 'Patterns', { size: 34, display: true });
  const kr = d.row(pat, { gap: 20, w: 'fill' });
  d.kpi(kr, 'Sales today', '₱ 186,400', { delta: '+12% vs last Sat', spark: [3, 5, 4, 6, 7, 6, 9], iconName: 'banknotes' });
  d.kpi(kr, 'Units in shop', '14', { delta: '3 ready for pick-up', spark: [8, 9, 11, 10, 12, 13, 14], iconName: 'truck' });
  d.kpi(kr, 'Average rating', '4.9', { delta: '38 new reviews', spark: [4.6, 4.7, 4.8, 4.8, 4.9, 4.9, 4.9], iconName: 'star' });
  const c = d.card(pat, { title: 'Recent jobs', sub: 'A card with a table', action: 'View all' });
  d.table(c, [{ label: 'Customer', w: 220 }, { label: 'Unit', w: 'fill' }, { label: 'Branch', w: 140 }, { label: 'Status', w: 140 }], [
    ['Miguel Santos', '2023 BMW 7 Series · Sedan', 'Cainta', (x) => d.chip(x, 'In progress')],
    ['Andrea Reyes', '2022 Toyota Land Cruiser 300 · SUV', 'Quezon City', (x) => d.chip(x, 'Ready')],
  ]);
  const ar = d.row(pat, { gap: 14 });
  for (const [i, col] of [['MS', C.accent], ['AR', C.gold], ['PD', C.info], ['CM', C.ok]]) d.avatar(ar, i, { color: col });
  const ir = d.row(pat, { gap: 22, wrap: true, w: 'fill', crossGap: 16 });
  for (const ic of ['calendar-days', 'queue-list', 'chat-bubble-left-right', 'user-group', 'shield-check', 'gift', 'photo', 'key', 'truck', 'wrench-screwdriver', 'bell-alert', 'clock', 'arrow-right-end-on-rectangle', 'arrow-left-start-on-rectangle', 'device-phone-mobile', 'star']) d.icon(ir, ic, { size: 24, color: C.muted });
}
