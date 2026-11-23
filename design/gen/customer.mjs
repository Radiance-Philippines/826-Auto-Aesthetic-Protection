// Page 4: customer app (iOS + Android, 390×844) and public website screens.
export default function customer(d) {
  const { C } = d;
  d.page('Customer app', { cols: 4, w: 390, h: 844, gapX: 100, gapY: 220, device: 'iPhone 17', responsive: true });

  const TABS = [
    { label: 'Home', icon: 'home', to: 'C1' },
    { label: 'Book', icon: 'calendar-days', to: 'C2' },
    { label: 'Garage', icon: 'truck', to: 'C4' },
    { label: 'Rewards', icon: 'gift', to: 'C6' },
    { label: 'Me', icon: 'user-circle' },
  ];

  // ---------- C1 Home ----------
  {
    const c = d.mobileShell('C1', 'Hi, Miguel', { right: 'bell', tabs: TABS, active: 0, covers: [7, 13, 27], note: 'Next job live, garage, referral code' });
    const job = d.card(c, { pad: 16, gap: 12, name: 'Current job' });
    const top = d.row(job, { w: 'fill', align: 'spaceBetween' });
    d.text(top, 'In the shop now', { size: 12, weight: 600, color: C.muted, upper: true, ls: '6%' });
    d.chip(top, 'In progress');
    d.unit(job, { car: 'bmw', year: 2023, model: 'BMW 7 Series', type: 'Sedan', plate: 'NDA 4821', compact: true });
    d.stepper(job, 1, { steps: ['Arrived', 'Working', 'Check', 'Ready'], w: 'fill' });
    const eta = d.row(job, { w: 'fill', gap: 8, pad: [10, 12, 10, 12], radius: 10, fill: C.s3 });
    d.icon(eta, 'calendar', { size: 16, color: C.accent });
    d.text(eta, 'Ready by Tue, 24 Nov · Cainta', { size: 13, weight: 600, w: 'fill' });
    d.link(job, 'C3');

    const ref = d.card(c, { pad: 16, gap: 10, name: 'Referral' });
    const rt = d.row(ref, { w: 'fill', gap: 10 });
    d.icon(rt, 'gift', { size: 20, color: C.gold });
    d.text(rt, 'Share your code. Friends get a discount, you earn points.', { size: 13, color: C.muted, w: 'fill', lh: '140%' });
    const code = d.row(ref, { w: 'fill', align: 'spaceBetween', pad: [10, 12, 10, 14], radius: 10, fill: C.s2, stroke: C.lineStrong });
    d.text(code, 'MIGUEL-826', { size: 16, weight: 800, ls: '6%' });
    d.button(code, 'Share', 'primary', { size: 'sm' });
    d.link(ref, 'C6');

    const gh = d.row(c, { w: 'fill', align: 'spaceBetween' });
    d.text(gh, 'My garage', { size: 15, weight: 700 });
    const all = d.text(gh, 'See all', { size: 13, weight: 600, color: C.accent });
    d.link(all, 'C4');
    const g = d.row(c, { w: 'fill', gap: 10 });
    for (const [k, name, sub] of [['lc300', 'Land Cruiser 300', '2022 · SUV'], ['p911', 'Porsche 911', '2024 · Coupe']]) {
      const t = d.col(g, { w: 'fill', pad: 12, gap: 6, radius: 14, fill: C.s2, stroke: C.line });
      d.car(t, k, { w: 150, h: 56 });
      d.text(t, name, { size: 13, weight: 700 });
      d.text(t, sub, { size: 12, color: C.muted });
    }
  }

  // ---------- local helpers ----------
  const lbl = (p, s) => d.text(p, s, { size: 12, weight: 600, color: C.muted, upper: true, ls: '6%' });
  const carTile = (p, key, w, h, r = 8) => {
    const t = d.frame(p, { dir: 'h', w, h, radius: r, fill: C.s3, align: 'center', cross: 'center', clip: true, name: 'Car tile' });
    d.car(t, key, { w: w - 4, h: h - 6 });
    return t;
  };
  const radio = (p, on) => {
    const r = d.frame(p, { dir: 'h', w: 18, h: 18, radius: 999, stroke: on ? C.accent : C.lineStrong, sw: 2, align: 'center', cross: 'center', name: 'Radio' });
    if (on) d.ellipse(r, { size: 8, fill: C.accent });
    return r;
  };
  // 5-point star, gold; filled or outline.
  const star = (p, size, on = true) => {
    const box = d.frame(p, { w: size, h: size, name: 'Star' });
    const R = size / 2;
    const pts = [];
    for (let i = 0; i < 10; i++) {
      const a = -Math.PI / 2 + (i * Math.PI) / 5;
      const rr = i % 2 ? R * 0.45 : R;
      pts.push(`${i ? 'L' : 'M'}${(R + rr * Math.cos(a)).toFixed(2)} ${(R + rr * Math.sin(a) + size * 0.04).toFixed(2)}`);
    }
    d.cmd('vector.create', { path: `${pts.join(' ')} Z`, x: 0, y: 0, parent: box, name: 'star', props: { fill: on ? C.gold : 'none', stroke: on ? C.gold : C.faint, strokeWeight: 1.4, strokeJoin: 'round' } });
    return box;
  };
  const stars = (p, n, size = 16) => {
    const r = d.row(p, { gap: 3, name: `Rating ${n} of 5` });
    for (let i = 0; i < 5; i++) star(r, size, i < n);
    return r;
  };
  const plusTile = (p, w, h) => {
    const t = d.col(p, { w, h, radius: 10, fill: C.s2, stroke: C.lineStrong, align: 'center', cross: 'center', gap: 4, name: 'Add tile' });
    d.icon(t, 'plus', { size: 20, color: C.accent });
    return t;
  };
  const textBox = (p, s, h, placeholder = false) => {
    const b = d.col(p, { w: 'fill', h, pad: 12, radius: 10, fill: C.s2, stroke: C.lineStrong, name: 'Text box' });
    d.text(b, s, { size: 14, color: placeholder ? C.faint : C.text, w: 'fill', lh: '140%' });
    return b;
  };

  // ---------- C2 Book ----------
  {
    const c = d.mobileShell('C2', 'Book a service', { tabs: TABS, active: 1, bigTitle: false, covers: [20, 22, 24, 27], note: 'Step: your unit, day estimate, referral code' });
    d.set(c, { gap: 10 });
    const sh = d.row(c, { w: 'fill', align: 'spaceBetween' });
    d.text(sh, 'Book a service', { size: 20, display: true });
    d.text(sh, 'Step 1 of 3 · Your unit', { size: 12, color: C.faint });
    const rows = d.col(c, { w: 'fill', gap: 5, name: 'Saved units' });
    [['bmw', '2023 BMW 7 Series', 'Sedan  ·  NDA 4821', true], ['lc300', '2022 Toyota Land Cruiser 300', 'SUV  ·  NEB 1190', false], ['p911', '2024 Porsche 911 Carrera', 'Coupe  ·  NFT 911', false]].forEach(([k, m, s, on]) => {
      const r = d.row(rows, { w: 'fill', pad: [5, 12, 5, 12], radius: 12, fill: on ? C.accentSoft : C.s2, stroke: on ? C.accent : C.line, gap: 12, name: `Unit row ${m}` });
      radio(r, on);
      carTile(r, k, 60, 32);
      const t = d.col(r, { gap: 2, w: 'fill' });
      d.text(t, m, { size: 13, weight: 700, w: 'fill', maxLines: 1 });
      d.text(t, s, { size: 11, color: C.muted });
    });
    const add = d.col(c, { w: 'fill', pad: 12, gap: 10, radius: 12, fill: C.s2, stroke: C.lineStrong, name: 'Add a unit' });
    const ah = d.row(add, { w: 'fill', gap: 8 });
    d.icon(ah, 'plus', { size: 16, color: C.accent });
    d.text(ah, 'Add a unit', { size: 13, weight: 700, w: 'fill' });
    const af = d.row(add, { w: 'fill', gap: 8, cross: 'min' });
    d.field(af, 'Year', '2025', { w: 78, h: 40, placeholder: true });
    d.field(af, 'Model', 'Model', { h: 40, placeholder: true });
    d.field(af, 'Type of unit', 'Type', { w: 116, h: 40, kind: 'select', placeholder: true });

    const sv = d.col(c, { w: 'fill', pad: [10, 12, 10, 12], gap: 8, radius: 12, fill: C.s2, stroke: C.line, name: 'Service' });
    const s1 = d.row(sv, { w: 'fill', gap: 8 });
    d.icon(s1, 'sparkles', { size: 16, color: C.gold });
    d.text(s1, 'Graphene coating', { size: 14, weight: 700, w: 'fill' });
    d.text(s1, 'Change', { size: 13, weight: 600, color: C.accent });
    const s2 = d.row(sv, { w: 'fill', gap: 8 });
    d.tag(s2, 'Estimated 3 days', { iconName: 'clock', color: C.accent, bg: C.accentSoft });
    d.tag(s2, 'Cainta', { iconName: 'map-pin' });

    const dh = d.row(c, { w: 'fill', align: 'spaceBetween' });
    lbl(dh, 'Drop-off day');
    d.text(dh, 'November', { size: 12, color: C.faint });
    const strip = d.row(c, { w: 'fill', gap: 5, cross: 'min', name: 'Date strip' });
    [['Sat', 21], ['Sun', 22, 'closed'], ['Mon', 23, 'sel'], ['Tue', 24, 'full'], ['Wed', 25], ['Thu', 26, 'full'], ['Fri', 27]].forEach(([w, n, st]) => {
      const full = st === 'full' || st === 'closed';
      const sel = st === 'sel';
      const cell = d.col(strip, { w: 'fill', h: 54, gap: 1, align: 'center', cross: 'center', radius: 10, fill: sel ? C.accentSoft : full ? C.s2 : C.s3, stroke: sel ? C.accent : C.line, name: `Day ${w} ${n}` });
      d.text(cell, w, { size: 10, weight: 600, color: sel ? C.accent : C.muted });
      d.text(cell, String(n), { size: 16, weight: 700, color: full ? C.faint : C.text });
      d.text(cell, st === 'closed' ? 'Closed' : full ? 'Full' : ' ', { size: 10, weight: 600, color: st === 'full' ? C.danger : C.faint });
    });

    const rf = d.col(c, { w: 'fill', gap: 5, name: 'Referral' });
    d.field(rf, 'Referral code', 'ANDREA-826', { h: 40, iconName: 'gift' });
    const ok = d.row(rf, { w: 'fill', gap: 6 });
    d.icon(ok, 'check-circle', { size: 14, color: C.ok });
    d.text(ok, 'Code applied — discount on this booking', { size: 12, color: C.ok, w: 'fill' });
    d.text(ok, '-₱ 2,000', { size: 12, weight: 700, color: C.ok });
    const go = d.button(c, 'Continue', 'primary');
    d.fill(go, 'h');
  }

  // ---------- C3 Live status ----------
  {
    const c = d.mobileShell('C3', 'Your unit', { back: { label: 'Home', to: 'C1' }, covers: [13, 22], note: 'Live status, day estimate, contact' });
    d.set(c, { gap: 12 });
    const top = d.row(c, { w: 'fill', align: 'spaceBetween' });
    d.unit(top, { car: 'bmw', year: 2023, model: 'BMW 7 Series', type: 'Sedan', plate: 'NDA 4821', compact: true });
    d.chip(top, 'In progress');
    const eta = d.row(c, { w: 'fill', gap: 10, pad: [12, 14, 12, 14], radius: 12, fill: C.accentSoft, stroke: '#2ad3cb55', name: 'Ready by' });
    d.icon(eta, 'calendar-days', { size: 20, color: C.accent });
    const et = d.col(eta, { gap: 1, w: 'fill' });
    d.text(et, 'Ready by Tue 24 Nov', { size: 15, weight: 700 });
    d.text(et, 'Estimated 3 days · Graphene coating · Cainta', { size: 12, color: C.muted });

    const tl = d.col(c, { w: 'fill', pad: [14, 14, 6, 14], radius: 14, fill: C.s2, stroke: C.line, gap: 0, name: 'Timeline' });
    const steps = [
      ['Booked', 'Thu 19 Nov', 'done'],
      ['Arrived', 'Sat 21 Nov, 9:12 AM', 'done'],
      ['In progress', 'Now · Jun Reyes is working on it', 'now'],
      ['Quality check', 'Next', 'todo'],
      ['Ready', 'Tue 24 Nov (estimate)', 'todo'],
      ['Picked up', 'After you collect it', 'todo'],
    ];
    steps.forEach(([name, sub, st], i) => {
      const r = d.row(tl, { w: 'fill', h: 44, gap: 12, cross: 'min', name: `Step ${name}` });
      const rail = d.col(r, { w: 16, gap: 0, cross: 'center', name: 'Rail' });
      d.fill(rail, 'v');
      if (st === 'now') d.frame(rail, { dir: 'h', w: 16, h: 16, radius: 999, stroke: '#2ad3cb66', sw: 3, fill: C.accent, name: 'Dot now' });
      else d.ellipse(rail, { size: 14, fill: st === 'done' ? C.accent : C.s4 });
      if (i < steps.length - 1) d.fill(d.rect(rail, { w: 2, h: 10, fill: st === 'done' ? C.accent : C.s4, name: 'Line' }), 'v');
      const t = d.col(r, { gap: 1, w: 'fill' });
      d.text(t, name, { size: 14, weight: st === 'now' ? 800 : 600, color: st === 'todo' ? C.faint : C.text });
      d.text(t, sub, { size: 12, color: st === 'now' ? C.accent : C.muted, w: 'fill', maxLines: 1 });
    });

    const ct = d.col(c, { w: 'fill', pad: 12, gap: 10, radius: 14, fill: C.s2, stroke: C.line, name: 'Contacts' });
    const hd = d.row(ct, { w: 'fill', gap: 10 });
    d.avatar(hd, 'JR', { size: 32, color: C.info });
    const hdt = d.col(hd, { gap: 1, w: 'fill' });
    d.text(hdt, 'Jun Reyes', { size: 14, weight: 700 });
    d.text(hdt, 'Head detailer · Cainta', { size: 12, color: C.muted });
    d.divider(ct);
    const sr = d.row(ct, { w: 'fill', gap: 10 });
    const bi = d.frame(sr, { dir: 'h', w: 32, h: 32, radius: 999, fill: C.accentSoft, align: 'center', cross: 'center', name: 'Branch icon' });
    d.icon(bi, 'building-storefront', { size: 16, color: C.accent });
    const srt = d.col(sr, { gap: 1, w: 'fill' });
    d.text(srt, '826 Cainta', { size: 14, weight: 700 });
    d.text(srt, 'Questions about your unit', { size: 12, color: C.muted });
    d.button(sr, 'Message', 'primary', { size: 'sm' });

    const ph = d.row(c, { w: 'fill', align: 'spaceBetween' });
    d.text(ph, 'Photos from the shop', { size: 14, weight: 700 });
    d.text(ph, 'Today', { size: 12, color: C.faint });
    const pr = d.row(c, { w: 'fill', gap: 8, name: 'Shop photos' });
    for (const k of ['bmw', 'bmw', 'bmw']) {
      const t = d.frame(pr, { dir: 'h', w: 'fill', h: 76, radius: 10, fill: C.s3, align: 'center', cross: 'center', clip: true, name: 'Photo' });
      d.car(t, k, { w: 100, h: 50 });
    }
  }

  // ---------- C4 My garage ----------
  {
    const c = d.mobileShell('C4', 'My garage', { tabs: TABS, active: 2, covers: [27], note: 'Saved units for repeat bookings' });
    d.set(c, { gap: 14 });
    [
      ['bmw', 2023, 'BMW 7 Series', 'Sedan', 'NDA 4821', 'Exterior detailing · 14 Sep 2026'],
      ['lc300', 2022, 'Toyota Land Cruiser 300', 'SUV', 'NEB 1190', 'PPF full body · 3 Mar 2026'],
      ['p911', 2024, 'Porsche 911 Carrera', 'Coupe', 'NFT 911', 'Nano ceramic tint · 18 Jun 2026'],
    ].forEach(([k, y, m, t, p, last]) => {
      const card = d.col(c, { w: 'fill', pad: 14, gap: 12, radius: 16, fills: [{ type: 'linearGradient', stops: [C.panelTop, C.panelBottom] }], stroke: C.line, name: `Unit card ${m}` });
      const top = d.row(card, { w: 'fill', gap: 12 });
      carTile(top, k, 104, 58, 10);
      const tx = d.col(top, { gap: 3, w: 'fill' });
      d.text(tx, `${y} ${m}`, { size: 14, weight: 700, w: 'fill', maxLines: 1 });
      d.text(tx, `${t}  ·  ${p}`, { size: 12, color: C.muted, w: 'fill', maxLines: 1 });
      const ls = d.row(tx, { gap: 5, w: 'fill' });
      d.icon(ls, 'clock', { size: 12, color: C.faint });
      d.text(ls, last, { size: 11, color: C.faint, w: 'fill', maxLines: 1 });
      const ac = d.row(card, { w: 'fill', gap: 8 });
      d.button(ac, 'Book again', 'primary', { size: 'sm' });
      const wb = d.button(ac, 'Warranty', 'secondary', { size: 'sm' });
      d.link(wb, 'C5');
      const rb = d.button(ac, 'Review', 'ghost', { size: 'sm' });
      d.link(rb, 'C7');
    });
    const add = d.col(c, { w: 'fill', pad: 14, gap: 10, radius: 16, fill: C.s2, stroke: C.lineStrong, name: 'Add a unit' });
    const ah = d.row(add, { w: 'fill', gap: 8 });
    d.icon(ah, 'plus', { size: 18, color: C.accent });
    d.text(ah, 'Add a unit', { size: 14, weight: 700, w: 'fill' });
    const af = d.row(add, { w: 'fill', gap: 8, cross: 'min' });
    d.field(af, 'Year', '2025', { w: 78, h: 40, placeholder: true });
    d.field(af, 'Model', 'Model', { h: 40, placeholder: true });
    d.field(af, 'Type of unit', 'Type', { w: 116, h: 40, kind: 'select', placeholder: true });
  }

  // ---------- C5 Warranty ----------
  {
    const c = d.mobileShell('C5', 'Warranty', { back: { label: 'Garage', to: 'C4' }, covers: [9], note: 'Coverage per service, request a backjob' });
    d.set(c, { gap: 10 });
    const sel = d.row(c, { w: 'fill', pad: [8, 14, 8, 10], radius: 12, fill: C.s2, stroke: C.lineStrong, gap: 12, name: 'Unit picker' });
    carTile(sel, 'bmw', 64, 36);
    const st = d.col(sel, { gap: 2, w: 'fill' });
    d.text(st, '2023 BMW 7 Series', { size: 14, weight: 700 });
    d.text(st, 'Sedan  ·  NDA 4821', { size: 12, color: C.muted });
    d.icon(sel, 'chevron-down', { size: 16, color: C.muted });

    [
      ['Nano ceramic tint', 'Active', 'Covered until 8 Mar 2027', ['Bubbling', 'Peeling edges', 'Fading']],
      ['Exterior detailing', 'Active', 'Covered until 14 Dec 2026', ['Swirl marks', 'Water spots']],
      ['PPF windshield', 'Expired', 'Covered until 12 Jun 2026', null],
    ].forEach(([name, status, until, types]) => {
      const card = d.col(c, { w: 'fill', pad: 12, gap: 8, radius: 14, fill: C.s2, stroke: C.line, name: `Warranty ${name}` });
      const h = d.row(card, { w: 'fill', align: 'spaceBetween' });
      d.text(h, name, { size: 14, weight: 700 });
      d.chip(h, status);
      const u = d.row(card, { gap: 6 });
      d.icon(u, 'shield-check', { size: 14, color: status === 'Active' ? C.ok : C.faint });
      d.text(u, until, { size: 12, color: C.muted });
      if (!types) d.text(card, 'Backjobs are no longer covered for this service.', { size: 12, color: C.faint, w: 'fill' });
      if (types) {
        const tr = d.row(card, { w: 'fill', gap: 6, wrap: true, crossGap: 6 });
        d.text(tr, 'Backjobs covered', { size: 11, color: C.faint });
        types.forEach((t) => d.tag(tr, t, { size: 11 }));
      }
    });

    const f = d.col(c, { w: 'fill', pad: 14, gap: 10, radius: 14, fill: C.s2, stroke: C.lineStrong, name: 'Request a backjob' });
    d.text(f, 'Request a backjob', { size: 14, weight: 700 });
    d.field(f, 'Service', 'Nano ceramic tint', { kind: 'select', h: 40 });
    textBox(f, 'What went wrong? Tell us what you noticed and where.', 56, true);
    const pr = d.row(f, { w: 'fill', gap: 8 });
    plusTile(pr, 52, 44);
    d.text(pr, 'Add a photo of the problem', { size: 12, color: C.muted, w: 'fill' });
    const b = d.button(f, 'Request a backjob', 'primary');
    d.fill(b, 'h');
  }

  // ---------- C6 Rewards / referral ----------
  {
    const c = d.mobileShell('C6', 'Rewards', { tabs: TABS, active: 3, covers: [20], note: 'Referral code, how it works, who used it' });
    d.set(c, { gap: 12 });
    const hero = d.col(c, { w: 'fill', pad: 18, gap: 12, radius: 16, fills: [{ type: 'linearGradient', stops: ['#cdb57a22', C.panelBottom] }], stroke: '#cdb57a44', cross: 'center', name: 'Code card' });
    lbl(hero, 'Your referral code');
    d.text(hero, 'MIGUEL-826', { size: 34, display: true, ls: '4%' });
    d.text(hero, 'Friends who book with this code get a discount on their first booking. You earn points for each one.', { size: 13, color: C.muted, w: 'fill', align: 'center', lh: '140%' });
    const pts = d.row(hero, { w: 'fill', pad: [10, 14, 10, 14], radius: 12, fill: C.s2, gap: 10, name: 'Points' });
    d.icon(pts, 'star', { size: 20, color: C.gold });
    d.text(pts, 'Your points', { size: 13, color: C.muted, w: 'fill' });
    d.text(pts, '300', { size: 20, weight: 800, color: C.gold });
    const br = d.row(hero, { w: 'fill', gap: 10 });
    const cp = d.button(br, 'Copy code', 'secondary');
    d.fill(cp, 'h');
    const sh = d.button(br, 'Share', 'primary');
    d.fill(sh, 'h');

    const how = d.col(c, { w: 'fill', pad: 14, gap: 12, radius: 14, fill: C.s2, stroke: C.line, name: 'How it works' });
    d.text(how, 'How it works', { size: 14, weight: 700 });
    ['Share your code with a friend', 'Your friend books with it', 'They get a discount, you get points'].forEach((s, i) => {
      const r = d.row(how, { w: 'fill', gap: 12 });
      const n = d.frame(r, { dir: 'h', w: 26, h: 26, radius: 999, fill: C.s3, align: 'center', cross: 'center' });
      d.text(n, String(i + 1), { size: 12, weight: 800, color: C.gold });
      d.text(r, s, { size: 13, w: 'fill' });
    });

    const fr = d.col(c, { w: 'fill', pad: 14, gap: 10, radius: 14, fill: C.s2, stroke: C.line, name: 'Friends' });
    const fh = d.row(fr, { w: 'fill', align: 'spaceBetween' });
    d.text(fh, '3 friends used your code', { size: 14, weight: 700 });
    d.icon(fh, 'user-group', { size: 18, color: C.faint });
    [['AR', 'Andrea Reyes', '2 Nov 2026', C.gold], ['PD', 'Paolo Dizon', '9 Nov 2026', C.info], ['CM', 'Carla Mendoza', '17 Nov 2026', C.ok]].forEach(([i, n, dt, col], k) => {
      if (k) d.divider(fr);
      const r = d.row(fr, { w: 'fill', gap: 10 });
      d.avatar(r, i, { size: 30, color: col });
      const nt = d.col(r, { gap: 1, w: 'fill' });
      d.text(nt, n, { size: 14, weight: 600 });
      d.text(nt, dt, { size: 12, color: C.muted });
      d.text(r, '+100 pts', { size: 13, weight: 700, color: C.gold });
    });
  }

  // ---------- C7 Write a review ----------
  {
    const c = d.mobileShell('C7', 'Write a review', { back: { label: 'Garage', to: 'C4' }, covers: [5], note: 'Stars, text, photos, YouTube link' });
    d.set(c, { gap: 16 });
    const u = d.row(c, { w: 'fill', pad: [8, 12, 8, 10], radius: 12, fill: C.s2, stroke: C.line, gap: 12, name: 'Job' });
    carTile(u, 'bmw', 64, 36);
    const ut = d.col(u, { gap: 2, w: 'fill' });
    d.text(ut, '2023 BMW 7 Series', { size: 14, weight: 700 });
    d.text(ut, 'Exterior detailing · Cainta · 14 Sep 2026', { size: 12, color: C.muted, w: 'fill', maxLines: 1 });

    const rt = d.col(c, { w: 'fill', gap: 8 });
    lbl(rt, 'Your rating');
    const sr = d.row(rt, { gap: 8 });
    for (let i = 0; i < 5; i++) star(sr, 34, i < 4);

    const tx = d.col(c, { w: 'fill', gap: 8 });
    lbl(tx, 'Your review');
    textBox(tx, 'Brought the car in for an exterior detail. The paint is cleaner than when I bought it, and the team kept me updated every day.', 112);

    const ph = d.col(c, { w: 'fill', gap: 8 });
    lbl(ph, 'Photos');
    const pr = d.row(ph, { w: 'fill', gap: 8 });
    for (const k of ['bmw', 'bmw']) {
      const t = d.frame(pr, { dir: 'h', w: 96, h: 76, radius: 10, fill: C.s3, align: 'center', cross: 'center', clip: true, name: 'Photo' });
      d.car(t, k, { w: 92, h: 48 });
    }
    plusTile(pr, 96, 76);

    const vd = d.col(c, { w: 'fill', gap: 8 });
    lbl(vd, 'Add a video');
    d.field(vd, '', 'Paste a YouTube link', { placeholder: true, iconName: 'link', h: 44, hint: 'Videos are YouTube links' });

    const post = d.button(c, 'Post review', 'primary');
    d.fill(post, 'h');
    const n = d.row(c, { w: 'fill', gap: 8, cross: 'min' });
    d.icon(n, 'information-circle', { size: 16, color: C.faint });
    d.text(n, '826 checks reviews before they go on the website.', { size: 12, color: C.muted, w: 'fill' });
  }

  // ---------- website ----------
  // Own page so the desktop frames present on a desktop screen, not inside a phone.
  d.page('Website', { cols: 2, w: 1440, h: 1100, gapX: 160, gapY: 260, device: 'Desktop' });
  const site = (code, title, { h, covers, note, active }) => {
    const s = d.screen(code, title, { w: 1440, h, covers, note });
    d.set(s, { layout: 'vertical', gap: 0 });
    const nav = d.row(s, { w: 'fill', h: 76, pad: [0, 64, 0, 64], gap: 40, stroke: C.line, sides: [0, 0, 1, 0], name: 'Site nav' });
    const brand = d.row(nav, { gap: 12 });
    d.img(brand, d.MARK, { w: 44, h: 30, name: '826 mark' });
    d.text(brand, '826 Auto', { size: 15, weight: 800 });
    const links = d.row(nav, { gap: 32, w: 'fill' });
    for (const l of ['Services', 'Branches', 'Gallery', 'Reviews']) d.text(links, l, { size: 14, weight: l === active ? 700 : 500, color: l === active ? C.text : C.muted });
    d.button(nav, 'Book now', 'primary');
    return s;
  };

  // ---------- W1 Booking step on the website ----------
  {
    const s = site('W1', 'Booking step on the website', { h: 1100, covers: [20, 21, 22, 24], note: 'Unit without size, full days greyed, day estimate, referral code', active: '' });
    const body = d.col(s, { w: 'fill', h: 'fill', pad: [40, 64, 40, 64], gap: 28, name: 'Body' });
    const hd = d.row(body, { w: 'fill', align: 'spaceBetween', cross: 'max' });
    const ht = d.col(hd, { gap: 8 });
    d.text(ht, 'Book your slot', { size: 36, display: true });
    d.text(ht, 'Tell us about your unit and pick a drop-off day.', { size: 15, color: C.muted });
    d.stepper(hd, 1, { steps: ['Service', 'Unit', 'Date', 'Details'], w: 560 });

    const main = d.row(body, { w: 'fill', h: 'fill', gap: 32, cross: 'min', name: 'Columns' });
    const left = d.col(main, { w: 'fill', gap: 24, name: 'Form' });
    d.fill(left, 'h');

    const u = d.card(left, { title: 'Your unit', sub: 'We keep it in your account for repeat bookings.', pad: 24, gap: 18 });
    const ur = d.row(u, { w: 'fill', gap: 16, cross: 'min' });
    d.field(ur, 'Year', '2023', { w: 150, kind: 'select' });
    d.field(ur, 'Make & model', 'BMW 7 Series', {});
    d.field(ur, 'Type of unit', 'Sedan', { w: 220, kind: 'select' });
    const hn = d.row(u, { gap: 8 });
    d.icon(hn, 'information-circle', { size: 16, color: C.faint });
    d.text(hn, 'We confirm the size class after reviewing your unit.', { size: 13, color: C.muted });

    const br = d.card(left, { pad: 24, gap: 14 });
    d.text(br, 'Branch', { size: 15, weight: 700 });
    d.segmented(br, ['Cainta', 'Quezon City', 'San Mateo'], 0);

    const cal = d.card(left, { pad: 24, gap: 14, name: 'Calendar' });
    const ch = d.row(cal, { w: 'fill', align: 'spaceBetween' });
    const ct = d.col(ch, { gap: 2 });
    d.text(ct, 'Pick a drop-off day', { size: 15, weight: 700 });
    d.text(ct, 'November 2026 · Cainta', { size: 12, color: C.muted });
    const nv = d.row(ch, { gap: 8 });
    for (const ic of ['chevron-left', 'chevron-right']) {
      const b = d.frame(nv, { dir: 'h', w: 34, h: 34, radius: 999, fill: C.s3, align: 'center', cross: 'center' });
      d.icon(b, ic, { size: 16, color: C.text });
    }
    const wk = d.row(cal, { w: 'fill', gap: 8 });
    for (const w of ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']) d.fill(d.text(wk, w, { size: 11, weight: 600, color: C.faint, upper: true, ls: '6%', align: 'center' }), 'h');
    const FULL = [24, 26, 28];
    const CLOSED = [22, 29];
    for (let w = 0; w < 5; w++) {
      const r = d.row(cal, { w: 'fill', gap: 8, cross: 'min' });
      for (let i = 0; i < 7; i++) {
        const n = w * 7 + i + 1;
        if (n > 30) {
          d.fill(d.rect(r, { w: 10, h: 58, fill: 'none', name: 'Spacer' }), 'h');
          continue;
        }
        const past = n < 21;
        const closed = CLOSED.includes(n);
        const full = FULL.includes(n) || closed;
        const sel = n === 23;
        const cell = d.col(r, { w: 'fill', h: 58, gap: 2, align: 'center', cross: 'center', radius: 10, fill: sel ? C.accentSoft : past ? 'none' : full ? C.s2 : C.s3, stroke: past ? undefined : sel ? C.accent : n === 21 ? C.lineStrong : C.line, name: `Day ${n}` });
        d.text(cell, String(n), { size: 16, weight: 700, color: sel ? C.accent : past || full ? C.faint : C.text });
        if (closed) d.text(cell, 'Closed', { size: 10, weight: 600, color: C.faint });
        else if (full) d.text(cell, 'Fully booked', { size: 10, weight: 600, color: C.danger });
        else if (n === 21) d.text(cell, 'Today', { size: 10, color: C.muted });
      }
    }

    const sum = d.card(main, { w: 400, title: 'Your booking', pad: 24, gap: 16, name: 'Summary' });
    const srow = (a, b) => {
      const r = d.row(sum, { w: 'fill', align: 'spaceBetween', cross: 'min', gap: 16 });
      d.text(r, a, { size: 13, color: C.muted });
      d.text(r, b, { size: 14, weight: 600, align: 'right', w: 'fill' });
    };
    srow('Service', 'Graphene coating');
    srow('Unit', '2023 BMW 7 Series · Sedan');
    srow('Branch', 'Cainta');
    srow('Drop-off', 'Mon 23 Nov 2026');
    d.divider(sum);
    const est = d.row(sum, { w: 'fill', gap: 12, pad: [12, 14, 12, 14], radius: 12, fill: C.accentSoft });
    d.icon(est, 'clock', { size: 20, color: C.accent });
    const et = d.col(est, { gap: 1, w: 'fill' });
    d.text(et, 'Estimated 3 days', { size: 15, weight: 700 });
    d.text(et, 'Ready by Wed 25 Nov', { size: 12, color: C.muted });
    const rf = d.col(sum, { w: 'fill', gap: 8 });
    d.field(rf, 'Referral code', 'ANDREA-826', { iconName: 'gift' });
    const ok = d.row(rf, { w: 'fill', gap: 6 });
    d.icon(ok, 'check-circle', { size: 14, color: C.ok });
    d.text(ok, 'Code applied — discount on this booking', { size: 12, color: C.ok, w: 'fill' });
    d.divider(sum);
    const dr = d.row(sum, { w: 'fill', align: 'spaceBetween' });
    d.text(dr, 'Referral discount', { size: 13, color: C.muted });
    d.text(dr, '-₱ 2,000', { size: 14, weight: 700, color: C.ok });
    const go = d.button(sum, 'Continue to details', 'primary');
    d.fill(go, 'h');
    d.text(sum, 'You pay nothing now. A branch will confirm the day.', { size: 12, color: C.faint, w: 'fill' });
  }

  // ---------- W2 Reviews & media ----------
  {
    const s = site('W2', 'Reviews and media on the website', { h: 1080, covers: [5], note: 'Reviews with photos, YouTube videos', active: 'Reviews' });
    const body = d.col(s, { w: 'fill', h: 'fill', pad: [48, 64, 48, 64], gap: 36, name: 'Body' });
    const hd = d.row(body, { w: 'fill', align: 'spaceBetween', cross: 'max' });
    const ht = d.col(hd, { gap: 12, w: 700 });
    lbl(ht, 'Reviews');
    d.text(ht, 'What our customers say', { size: 36, display: true, w: 'fill', lh: '110%' });
    d.text(ht, 'Every review is checked by our team before it appears here.', { size: 15, color: C.muted });
    const sm = d.row(hd, { gap: 28, cross: 'center' });
    const big = d.col(sm, { gap: 6, cross: 'center' });
    d.text(big, '4.9', { size: 64, weight: 800 });
    stars(big, 5, 18);
    d.text(big, '212 reviews', { size: 12, color: C.muted });
    const dist = d.col(sm, { gap: 6, w: 220 });
    [[5, 0.88], [4, 0.09], [3, 0.02], [2, 0.01], [1, 0]].forEach(([n, f]) => {
      const r = d.row(dist, { w: 'fill', gap: 8 });
      d.text(r, String(n), { size: 12, color: C.muted, w: 10 });
      const tr = d.row(r, { w: 'fill', h: 6, radius: 999, fill: C.s3 });
      d.fill(tr, 'h');
      if (f > 0) d.rect(tr, { w: Math.max(4, 170 * f), h: 6, radius: 999, fill: C.gold });
    });
    d.button(hd, 'Write a review', 'primary');

    const rv = d.row(body, { w: 'fill', gap: 24, cross: 'min', name: 'Reviews' });
    [
      ['bmw', 5, 'Three days in, and the paint looks deeper than the day I bought it. The team kept me updated the whole way.', 'Miguel Santos', '2023 BMW 7 Series · Graphene coating', 'MS', C.accent],
      ['lx600', 5, 'Clean edges and no lifting lines on the PPF. They walked me through the warranty before I left.', 'Andrea Reyes', '2023 Lexus LX 600 · PPF full body', 'AR', C.gold],
      ['mustang', 4, 'Tint is even on every window and they were on time. Bringing the next car here.', 'Paolo Dizon', '2022 Ford Mustang Dark Horse · Nano ceramic tint', 'PD', C.info],
    ].forEach(([k, n, t, who, what, ini, col]) => {
      const card = d.col(rv, { w: 421, gap: 0, radius: 16, clip: true, fills: [{ type: 'linearGradient', stops: [C.panelTop, C.panelBottom] }], stroke: C.line, name: `Review ${who}` });
      const ph = d.frame(card, { dir: 'h', w: 'fill', h: 190, fill: C.s3, align: 'center', cross: 'center', clip: true, name: 'Review photo' });
      d.car(ph, k, { w: 340, h: 150 });
      const b = d.col(card, { w: 'fill', pad: 22, gap: 12 });
      stars(b, n, 16);
      d.text(b, t, { size: 15, w: 'fill', lh: '150%' });
      const wr = d.row(b, { w: 'fill', gap: 10 });
      d.avatar(wr, ini, { size: 36, color: col });
      const wt = d.col(wr, { gap: 1, w: 'fill' });
      d.text(wt, who, { size: 14, weight: 700 });
      d.text(wt, what, { size: 12, color: C.muted, w: 'fill', maxLines: 1 });
    });

    const vh = d.row(body, { w: 'fill', align: 'spaceBetween' });
    d.text(vh, 'Watch the work', { size: 24, display: true });
    d.text(vh, 'Videos play on YouTube', { size: 13, color: C.muted });
    const vr = d.row(body, { w: 'fill', gap: 24, cross: 'min', name: 'Videos' });
    [
      ['bmw', 'Graphene coating on a 7 Series, start to finish', '8:42'],
      ['lc300', 'PPF full body on a Land Cruiser 300', '6:15'],
      ['lx600', 'Interior detailing: Lexus LX 600', '4:30'],
    ].forEach(([k, title, dur]) => {
      const card = d.col(vr, { w: 421, gap: 0, radius: 16, clip: true, fill: C.s2, stroke: C.line, name: `Video ${title}` });
      const th = d.frame(card, { dir: 'h', w: 421, h: 170, fill: C.s3, align: 'center', cross: 'center', clip: true, name: 'Thumbnail' });
      d.car(th, k, { w: 300, h: 130 });
      const pb = d.frame(th, { dir: 'h', w: 54, h: 54, radius: 999, fill: '#060708cc', stroke: C.lineStrong, align: 'center', cross: 'center', abs: true, x: 184, y: 58, name: 'Play' });
      d.icon(pb, 'play', { size: 22, color: C.text });
      const dt = d.row(th, { pad: [3, 8, 3, 8], radius: 6, fill: '#060708cc', abs: true, x: 360, y: 138, name: 'Duration' });
      d.text(dt, dur, { size: 11, weight: 600 });
      const vb = d.col(card, { w: 'fill', pad: 18, gap: 10 });
      d.text(vb, title, { size: 15, weight: 700, w: 'fill', maxLines: 2 });
      const wy = d.row(vb, { gap: 6 });
      d.icon(wy, 'play-circle', { size: 16, color: C.accent });
      d.text(wy, 'Watch on YouTube', { size: 13, weight: 600, color: C.accent });
    });
  }

  d.flow('C1', 'Customer app');
  d.flow('W1', 'Website booking');
}
