// Page 3: staff app (iOS + Android, 390×844). One app, tabs and screens change by department.
export default function staff(d) {
  const { C } = d;
  d.page('Staff app', { cols: 4, w: 390, h: 844, gapX: 100, gapY: 220, device: 'iPhone 17', responsive: true });

  const HEAD_TABS = [
    { label: 'Queue', icon: 'queue-list', to: 'S2' },
    { label: 'Unit', icon: 'truck', to: 'S3' },
    { label: 'Inbox', icon: 'chat-bubble-left-right', to: 'S5' },
    { label: 'Me', icon: 'user-circle' },
  ];

  const SALES_TABS = [
    { label: 'Clients', icon: 'user-group', to: 'S4' },
    { label: 'Inbox', icon: 'chat-bubble-left-right', to: 'S5' },
    { label: 'Bookings', icon: 'calendar-days' },
    { label: 'Me', icon: 'user-circle' },
  ];
  const MGR_TABS = [
    { label: 'Today', icon: 'home', to: 'S6' },
    { label: 'Operations', icon: 'queue-list', to: 'S2' },
    { label: 'Sales', icon: 'banknotes', to: 'S4' },
    { label: 'Me', icon: 'user-circle' },
  ];
  const OWNER_TABS = [
    { label: 'Overview', icon: 'squares-2x2', to: 'S7' },
    { label: 'Branches', icon: 'building-storefront', to: 'S6' },
    { label: 'Inbox', icon: 'chat-bubble-left-right', to: 'S5' },
    { label: 'Me', icon: 'user-circle' },
  ];

  const toggle = (p, on = true) => {
    const t = d.frame(p, { dir: 'h', w: 46, h: 28, radius: 999, fill: on ? C.accent : C.s4, align: on ? 'max' : 'min', cross: 'center', pad: 3, name: on ? 'Switch on' : 'Switch off' });
    d.ellipse(t, { size: 22, fill: on ? C.onAccent : C.muted, name: 'Knob' });
    return t;
  };
  const roundBtn = (p, name, color = C.muted) => {
    const b = d.frame(p, { dir: 'h', w: 34, h: 34, radius: 999, fill: C.s3, align: 'center', cross: 'center', name: `Action ${name}` });
    d.icon(b, name, { size: 16, color });
    return b;
  };

  // ---------- S1 Sign in + 2FA ----------
  {
    const s = d.screen('S1', 'Sign in + 2FA', { covers: [1, 12], note: 'One app for every department' });
    d.set(s, { layout: 'vertical', gap: 0, cornerRadius: 44 });
    d.statusBar(s);
    const body = d.col(s, { w: 'fill', h: 'fill', pad: [72, 24, 28, 24], gap: 22, cross: 'center', name: 'Body' });
    d.img(body, d.MARK, { w: 120, h: 80, name: '826 mark' });
    const t = d.col(body, { w: 'fill', gap: 8, cross: 'center' });
    d.text(t, 'Two-step check', { size: 26, display: true, w: 'fill', align: 'center' });
    d.text(t, 'Enter the code from your authenticator app', { size: 15, color: C.muted, w: 'fill', align: 'center' });
    const codes = d.row(body, { gap: 10, name: 'Code boxes' });
    ['4', '8', '2', '', '', ''].forEach((v, i) => {
      const b = d.frame(codes, { dir: 'h', w: 48, h: 60, radius: 12, fill: C.s2, stroke: i === 3 ? C.accent : C.lineStrong, sw: i === 3 ? 2 : 1, align: 'center', cross: 'center', name: `Code ${i + 1}` });
      if (v) d.text(b, v, { size: 26, weight: 700 });
    });
    const v = d.button(body, 'Verify', 'primary');
    d.fill(v);
    d.link(v, 'S2');
    d.button(body, 'Use a backup code', 'ghost');
    d.col(body, { w: 'fill', h: 'fill', name: 'Spacer' });
    const n = d.row(body, { w: 'fill', pad: 14, gap: 12, radius: 14, fill: C.s2, stroke: C.line, cross: 'min', name: 'Roles note' });
    d.icon(n, 'identification', { size: 20, color: C.accent });
    d.text(n, 'Your department decides what you see: Head detailer, Sales, Branch manager, Owner.', { size: 13, color: C.muted, w: 'fill', lh: '140%' });
  }

  // ---------- S2 Head detailer queue ----------
  {
    const c = d.mobileShell('S2', "Today's queue", {
      role: 'Head detailer · Cainta',
      right: 'bell',
      sub: 'Sat 21 Nov · 8 units, no bays: one queue for the branch',
      tabs: HEAD_TABS,
      active: 0,
      covers: [10, 13, 14],
      note: 'Head detailer moves each unit along',
    });
    const f = d.row(c, { gap: 8, w: 'fill' });
    d.tag(f, 'All 8', { color: C.onAccent, bg: C.accent });
    d.tag(f, 'Arrived 2');
    d.tag(f, 'In progress 3');
    d.tag(f, 'Ready 2');
    const jobs = [
      { car: 'bmw', year: 2023, model: 'BMW 7 Series', type: 'Sedan', plate: 'NDA 4821', status: 'In progress', svc: 'Graphene coating', day: 'Day 1 of 3', next: 'Send to quality check' },
      { car: 'lc300', year: 2022, model: 'Toyota Land Cruiser 300', type: 'SUV', plate: 'NEB 1190', status: 'Arrived', svc: 'PPF full body', day: 'Day 1 of 5', next: 'Start work' },
      { car: 'p911', year: 2024, model: 'Porsche 911 Carrera', type: 'Coupe', plate: 'NFT 911', status: 'Ready', svc: 'Nano ceramic tint', day: 'Done today', next: 'Mark as exited' },
    ];
    jobs.forEach((j, i) => {
      const k = d.card(c, { pad: 14, gap: 12, name: `Job ${j.model}` });
      const top = d.row(k, { w: 'fill', align: 'spaceBetween' });
      d.chip(top, j.status);
      d.text(top, j.day, { size: 12, weight: 600, color: C.muted });
      d.unit(k, { car: j.car, year: j.year, model: j.model, type: j.type, plate: j.plate, compact: true });
      const m = d.row(k, { w: 'fill', align: 'spaceBetween' });
      const sv = d.row(m, { gap: 6 });
      d.icon(sv, 'sparkles', { size: 16, color: C.faint });
      d.text(sv, j.svc, { size: 13, color: C.muted });
      const crew = d.row(m, { gap: -6 });
      d.avatar(crew, 'JR', { size: 24, color: C.info });
      d.avatar(crew, 'AL', { size: 24, color: C.gold });
      const b = d.button(k, j.next, i === 0 ? 'primary' : 'secondary', { size: 'sm' });
      d.fill(b);
      d.link(k, 'S3');
    });
  }

  // ---------- S3 Unit update ----------
  {
    const c = d.mobileShell('S3', 'Unit update', {
      back: { label: 'Queue', to: 'S2' },
      right: 'bell',
      tabs: HEAD_TABS,
      active: 1,
      bigTitle: false,
      covers: [10, 13, 15, 22],
      note: 'Head detailer updates one unit',
    });
    d.set(c, { gap: 10 });
    const k = d.card(c, { pad: 12, gap: 8, name: 'Unit card' });
    const pic = d.frame(k, { dir: 'h', w: 'fill', h: 112, radius: 12, fill: C.s2, align: 'center', cross: 'center', name: 'Car tile' });
    d.car(pic, 'bmw', { w: 300, h: 104 });
    const ur = d.row(k, { w: 'fill', align: 'spaceBetween' });
    const ut = d.col(ur, { gap: 2, w: 'fill' });
    d.text(ut, '2023 BMW 7 Series', { size: 16, weight: 700 });
    d.text(ut, 'Sedan · NDA 4821 · Miguel Santos', { size: 12, color: C.muted });
    d.chip(ur, 'In progress');

    const list = d.col(c, { w: 'fill', gap: 7, pad: [4, 4, 0, 4], name: 'Status list' });
    [['Booked', 'Thu 19 Nov', 'done'], ['Arrived', '9:12 AM', 'done'], ['In progress', 'Day 1 of 3', 'now'], ['Quality check', '', 'todo'], ['Ready', '', 'todo'], ['Exited', '', 'todo']].forEach(([l, tm, st]) => {
      const r = d.row(list, { w: 'fill', gap: 10, h: 24 });
      if (st === 'done') d.icon(r, 'check-circle', { size: 20, color: C.ok });
      else if (st === 'now') d.ellipse(r, { size: 20, fill: C.accent, stroke: '#2ad3cb55' });
      else d.ellipse(r, { size: 20, fill: C.s3 });
      d.text(r, l, { size: 14, weight: st === 'now' ? 700 : 500, color: st === 'todo' ? C.faint : C.text, w: 'fill' });
      if (tm) d.text(r, tm, { size: 12, color: st === 'now' ? C.accent : C.muted, weight: 600 });
    });

    const go = d.button(c, 'Send to quality check', 'primary');
    d.fill(go);

    const est = d.row(c, { w: 'fill', pad: 12, gap: 10, radius: 14, fill: C.s2, stroke: C.line, name: 'Day estimate' });
    d.icon(est, 'calendar-days', { size: 20, color: C.gold });
    const et = d.col(est, { gap: 2, w: 'fill' });
    d.text(et, 'Day 1 of 3 · ready by Tue 24 Nov', { size: 13, weight: 700, w: 'fill' });
    d.text(et, 'Estimate in days only', { size: 12, color: C.muted });
    d.button(est, 'Change', 'secondary', { size: 'sm' });

    const hu = d.row(c, { w: 'fill', pad: 12, gap: 10, radius: 14, fill: C.goldSoft, cross: 'min', name: 'Heads-up to sales' });
    d.icon(hu, 'bell-alert', { size: 20, color: C.gold });
    const ht = d.col(hu, { gap: 2, w: 'fill' });
    d.text(ht, 'Heads-up to sales', { size: 13, weight: 700 });
    d.text(ht, 'Cainta sales get a heads-up 1 day before exit so the next unit can come in.', { size: 12, color: C.muted, w: 'fill', lh: '135%' });
    toggle(hu, true);

    const ar = d.row(c, { w: 'fill', gap: 10 });
    d.fill(d.button(ar, 'Add photo', 'secondary', { size: 'sm' }));
    d.fill(d.button(ar, 'Add note', 'secondary', { size: 'sm' }));
  }

  // ---------- S4 Sales rep: my clients ----------
  {
    const c = d.mobileShell('S4', 'Customers', {
      role: 'Sales rep · Cainta',
      right: 'bell',
      sub: 'Cainta · heads-ups and customers',
      tabs: SALES_TABS,
      active: 0,
      covers: [15],
      note: 'Sales sees heads-ups and the branch customers',
    });
    const alerts = [
      ['truck', C.gold, C.goldSoft, 'BMW 7 Series leaves Tue 24 Nov', 'Cainta can take the next unit Wed 25 Nov.'],
      ['check-circle', C.ok, C.okSoft, 'Range Rover ready for pick-up', 'Carla Mendoza has not been told yet.'],
    ];
    for (const [ic, fg, bg, t1, t2] of alerts) {
      const a = d.row(c, { w: 'fill', pad: 12, gap: 12, radius: 14, fill: bg, cross: 'min', name: 'Heads-up' });
      d.icon(a, ic, { size: 22, color: fg });
      const tt = d.col(a, { gap: 2, w: 'fill' });
      d.text(tt, t1, { size: 14, weight: 700, w: 'fill' });
      d.text(tt, t2, { size: 12, color: C.muted, w: 'fill' });
    }
    d.text(c, 'Cainta customers', { size: 12, weight: 600, color: C.faint, upper: true, ls: '8%' });
    const clients = [
      ['MS', 'Miguel Santos', '2023 BMW 7 Series', 'In progress', C.accent],
      ['CM', 'Carla Mendoza', '2021 Range Rover', 'Ready', C.ok],
      ['JC', 'Joaquin Cruz', '2023 Mercedes-AMG G 63', 'Booked', C.info],
      ['PD', 'Paolo Dizon', '2022 Ford Mustang Dark Horse', 'Arrived', C.gold],
    ];
    clients.forEach(([ini, name, unit, st, col]) => {
      const r = d.row(c, { w: 'fill', pad: 12, gap: 12, radius: 14, fill: C.s2, stroke: C.line, name: `Client ${name}` });
      d.avatar(r, ini, { size: 40, color: col });
      const t = d.col(r, { gap: 3, w: 'fill' });
      d.text(t, name, { size: 14, weight: 700, w: 'fill', maxLines: 1 });
      d.text(t, unit, { size: 12, color: C.muted, w: 'fill', maxLines: 1 });
      const rt = d.col(r, { gap: 8, cross: 'max' });
      d.chip(rt, st);
      const ic = d.row(rt, { gap: 8 });
      roundBtn(ic, 'chat-bubble-left', C.accent);
      roundBtn(ic, 'phone');
      d.link(r, 'S5');
    });
  }

  // ---------- S5 Inbox thread ----------
  {
    const c = d.mobileShell('S5', 'Miguel Santos', {
      back: { label: 'Clients', to: 'S4' },
      right: 'phone',
      sub: 'Cainta FB · Messenger',
      tabs: SALES_TABS,
      active: 1,
      covers: [2, 19],
      note: 'One inbox, a tab per source',
    });
    d.set(c, { gap: 10 });
    const chips = d.row(c, { w: 'fill', gap: 8, clip: true, name: 'Source tabs' });
    [['Cainta FB', 2, true], ['QC FB', 1], ['San Mateo FB', 0], ['Website', 3], ['IG', 0]].forEach(([l, n, on]) => {
      const t = d.tag(chips, n ? `${l}  ${n}` : l, on ? { color: C.onAccent, bg: C.accent } : {});
      void t;
    });
    const msgs = d.col(c, { w: 'fill', h: 'fill', gap: 8, name: 'Messages' });
    const bubble = (mine, txt, time) => {
      const line = d.row(msgs, { w: 'fill', align: mine ? 'max' : 'min' });
      const b = d.col(line, { gap: 3, pad: [9, 12, 9, 12], radius: 16, fill: mine ? C.accentSoft : C.s3, stroke: mine ? '#2ad3cb40' : undefined, w: 250, name: mine ? 'Staff bubble' : 'Customer bubble' });
      d.text(b, txt, { size: 14, w: 'fill', lh: '135%' });
      d.text(b, time, { size: 11, color: C.faint });
    };
    bubble(false, 'Hi! Can I bring the Land Cruiser in for PPF this week?', '10:02 AM');
    bubble(true, 'Hi Miguel, yes. Cainta can take it Wed 25 Nov, right after the BMW leaves. I am holding the slot.', '10:05 AM');
    const bc = d.row(msgs, { w: 'fill', align: 'max' });
    const card = d.col(bc, { w: 270, pad: 12, gap: 10, radius: 14, fill: C.s2, stroke: C.lineStrong, name: 'Booking card' });
    const bt = d.row(card, { w: 'fill', align: 'spaceBetween' });
    d.text(bt, 'Booking', { size: 11, weight: 600, color: C.faint, upper: true, ls: '8%' });
    d.chip(bt, 'Booked');
    d.unit(card, { car: 'lc300', year: 2022, model: 'Land Cruiser 300', type: 'SUV', plate: 'NEB 1190', compact: true });
    d.text(card, 'PPF full body · Wed 25 Nov · Cainta', { size: 12, color: C.muted, w: 'fill' });
    bubble(false, 'Perfect, thank you!', '10:06 AM');

    const rq = d.row(c, { w: 'fill', gap: 8, clip: true, name: 'Quick replies' });
    ['Slot confirmed', 'Send price list', 'Share location'].forEach((l) => d.tag(rq, l, { color: C.accent, bg: C.accentSoft }));
    const rb = d.row(c, { w: 'fill', gap: 10, name: 'Reply bar' });
    roundBtn(rb, 'plus');
    const inp = d.row(rb, { w: 'fill', h: 42, pad: [0, 14, 0, 14], radius: 999, fill: C.s2, stroke: C.lineStrong });
    d.text(inp, 'Reply to Miguel', { size: 14, color: C.faint, w: 'fill' });
    const send = d.frame(rb, { dir: 'h', w: 42, h: 42, radius: 999, fill: C.accent, align: 'center', cross: 'center', name: 'Send' });
    d.icon(send, 'paper-airplane', { size: 18, color: C.onAccent });
  }

  // ---------- S6 Branch manager: today ----------
  {
    const c = d.mobileShell('S6', 'Today', {
      role: 'Branch manager · Quezon City',
      right: 'bell',
      sub: 'Sat 21 Nov',
      tabs: MGR_TABS,
      active: 0,
      covers: [11],
      note: 'Branch manager sees operations and sales',
    });
    d.set(c, { gap: 10 });
    const bar = d.row(c, { w: 'fill', align: 'spaceBetween' });
    d.text(bar, 'Quezon City branch', { size: 12, weight: 600, color: C.faint, upper: true, ls: '8%' });
    d.sample(bar);
    const g1 = d.row(c, { w: 'fill', gap: 12, cross: 'min' });
    d.kpi(g1, 'Sales today', '₱ 142,500', { iconName: 'banknotes' });
    d.kpi(g1, 'Units in shop', '9', { iconName: 'truck' });
    const g2 = d.row(c, { w: 'fill', gap: 12, cross: 'min' });
    d.kpi(g2, 'Ready', '3', { iconName: 'check-circle' });
    d.kpi(g2, 'New bookings', '5', { iconName: 'calendar-days' });

    const ops = d.card(c, { title: 'Operations', action: 'Open', actionTo: 'S2', pad: 14, gap: 10 });
    const chips = d.row(ops, { w: 'fill', gap: 6, wrap: true, crossGap: 6 });
    [['Arrived', 1], ['In progress', 4], ['Quality check', 1], ['Ready', 3]].forEach(([s, n]) => {
      const [fg, bg] = d.STATUS[s];
      d.tag(chips, `${s} ${n}`, { color: fg, bg });
    });

    const sl = d.card(c, { title: 'Sales today', pad: 14, gap: 8 });
    [['Andrea Reyes', 'Lexus LX 600 · Graphene coating', '₱ 68,000'], ['Bea Villanueva', 'Ferrari Roma · Nano ceramic tint', '₱ 42,500']].forEach(([n, s, amt]) => {
      const r = d.row(sl, { w: 'fill', gap: 8 });
      const t = d.col(r, { gap: 1, w: 'fill' });
      d.text(t, n, { size: 13, weight: 600, w: 'fill', maxLines: 1 });
      d.text(t, s, { size: 11, color: C.muted, w: 'fill', maxLines: 1 });
      d.text(r, amt, { size: 13, weight: 700 });
    });

    const w = d.row(c, { w: 'fill', pad: 12, gap: 10, radius: 14, fill: C.dangerSoft, name: 'Fully booked warning' });
    d.icon(w, 'exclamation-triangle', { size: 20, color: C.danger });
    const wt = d.col(w, { gap: 1, w: 'fill' });
    d.text(wt, 'Fully booked tomorrow', { size: 13, weight: 700, color: C.danger });
    d.text(wt, 'Sun 22 Nov is at the daily unit cap.', { size: 12, color: C.muted, w: 'fill' });
  }

  // ---------- S7 Owner overview ----------
  {
    const c = d.mobileShell('S7', 'Overview', {
      role: 'Owner',
      right: 'bell',
      sub: 'All branches · Sat 21 Nov',
      tabs: OWNER_TABS,
      active: 0,
      covers: [3, 16],
      note: 'Owner sees the whole business',
    });
    d.set(c, { gap: 16 });
    const big = d.card(c, { pad: 16, gap: 8, name: 'Sales today' });
    const bt = d.row(big, { w: 'fill', align: 'spaceBetween' });
    d.text(bt, 'Sales today', { size: 12, weight: 600, color: C.muted, upper: true, ls: '6%' });
    d.sample(bt);
    const br = d.row(big, { w: 'fill', align: 'spaceBetween', cross: 'max' });
    const bn = d.col(br, { gap: 2 });
    d.text(bn, '₱ 412,800', { size: 32, weight: 700 });
    d.text(bn, '+12% vs last Saturday', { size: 12, weight: 600, color: C.ok });
    d.spark(br, [3, 5, 4, 6, 7, 6, 9, 8, 11], { w: 130, h: 48, area: true });

    const bc = d.card(c, { title: 'Branches', pad: 14, gap: 6 });
    [['Cainta', '₱ 168,200', '8 units', '4.9'], ['Quezon City', '₱ 142,500', '9 units', '4.8'], ['San Mateo', '₱ 102,100', '5 units', '4.9']].forEach(([n, s, u, rt]) => {
      const r = d.row(bc, { w: 'fill', gap: 8, h: 30 });
      d.text(r, n, { size: 13, weight: 600, w: 'fill' });
      d.text(r, s, { size: 13, weight: 700 });
      d.text(r, u, { size: 12, color: C.muted, w: 52 });
      const st = d.row(r, { gap: 3 });
      d.icon(st, 'star', { size: 14, color: C.gold });
      d.text(st, rt, { size: 12, weight: 600 });
      d.link(r, 'S6');
    });

    const lb = d.card(c, { title: 'Top sales reps', pad: 14, gap: 8 });
    const lr = d.row(lb, { w: 'fill', gap: 8, cross: 'min' });
    [['JC', 'Joanna Cruz', '₱ 168k', C.gold], ['MB', 'Mark Bautista', '₱ 142k', C.muted], ['RL', 'Rhea Lim', '₱ 102k', C.info]].forEach(([i, n, a, col], idx) => {
      const x = d.col(lr, { w: 'fill', gap: 4, cross: 'center' });
      d.avatar(x, i, { size: 36, color: col });
      d.text(x, `${idx + 1}. ${n.split(' ')[0]}`, { size: 12, weight: 600 });
      d.text(x, a, { size: 12, color: C.muted });
    });

    const bottom = d.row(c, { w: 'fill', gap: 12, cross: 'min' });
    const bj = d.card(bottom, { w: 110, pad: 14, gap: 4, name: 'Backjobs open' });
    d.text(bj, 'Backjobs open', { size: 11, weight: 600, color: C.muted, upper: true, ls: '6%', w: 'fill' });
    d.text(bj, '2', { size: 28, weight: 700, color: C.danger });
    const rv = d.card(bottom, { pad: 14, gap: 6, name: 'Latest review' });
    const rr = d.row(rv, { gap: 2 });
    for (let i = 0; i < 5; i++) d.icon(rr, 'star', { size: 14, color: C.gold });
    d.text(rv, '"Car looks brand new. Jun and the team were great."', { size: 12, w: 'fill', color: C.muted, lh: '135%' });
  }

  d.flow('S1', 'Staff app: sign in');
  d.flow('S2', 'Staff app: head detailer');
  d.flow('S4', 'Staff app: sales rep');
  d.flow('S7', 'Staff app: owner');
}
