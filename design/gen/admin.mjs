// Page 2: admin website (1440 wide). A1 is the reference screen for the rest.
export default function admin(d) {
  const { C } = d;
  const toggle = (p, on = true) => {
    const t = d.row(p, { w: 40, h: 22, pad: 3, radius: 11, fill: on ? C.accent : C.s4, align: on ? 'max' : 'min', gap: 0, name: on ? 'Switch on' : 'Switch off' });
    d.ellipse(t, { size: 16, fill: on ? C.onAccent : C.muted });
    return t;
  };
  const check = (p, on = true, size = 18) => {
    const b = d.frame(p, { dir: 'h', w: size, h: size, radius: 5, fill: on ? C.accent : 'none', stroke: on ? undefined : C.lineStrong, align: 'center', cross: 'center', name: on ? 'Checked' : 'Unchecked' });
    if (on) d.icon(b, 'check', { size: size - 6, color: C.onAccent });
    return b;
  };
  const person = (p, ini, name, color, sub) => {
    const r = d.row(p, { gap: 10 });
    d.avatar(r, ini, { size: 30, color });
    if (sub) {
      const t = d.col(r, { gap: 1 });
      d.text(t, name, { size: 14, weight: 600 });
      d.text(t, sub, { size: 12, color: C.muted });
    } else d.text(r, name, { size: 14, weight: 600 });
    return r;
  };
  const backLink = (p, label, to) => {
    const b = d.row(p, { gap: 4 });
    d.icon(b, 'chevron-left', { size: 18, color: C.accent });
    d.text(b, label, { size: 13, weight: 600, color: C.accent });
    d.link(b, to);
    return b;
  };
  const label = (p, s) => d.text(p, s, { size: 11, weight: 600, color: C.faint, upper: true, ls: '8%' });
  const kv = (p, k, v, o = {}) => {
    const r = d.row(p, { w: 'fill', align: 'spaceBetween' });
    d.text(r, k, { size: 13, color: C.muted });
    d.text(r, v, { size: 13, weight: o.bold ? 700 : 500, color: o.color ?? C.text });
    return r;
  };
  const strip = (p, iconName, s, { color = C.accent, bg = C.accentSoft } = {}) => {
    const r = d.row(p, { w: 'fill', pad: [10, 14, 10, 14], radius: 10, fill: bg, gap: 10 });
    d.icon(r, iconName, { size: 18, color });
    d.text(r, s, { size: 13, color: C.text, w: 'fill' });
    return r;
  };
  const sizeSel = (p, v, w = 140) => d.field(p, '', v, { w, kind: 'select', h: 38 });
  d.page('Admin website', { cols: 3, w: 1440, h: 960, gapX: 160, gapY: 260, device: 'Desktop' });

  // ---------- A1 Owner master dashboard ----------
  {
    const c = d.adminShell('A1', 'Owner dashboard', {
      sub: 'All branches · Saturday, 21 Nov',
      covers: [3, 16],
      note: 'Whole operation in one view: sales, jobs, reps, reviews',
      h: 1080,
      actions: [{ label: 'Export', kind: 'secondary' }],
    });
    const bar = d.row(c, { w: 'fill', align: 'spaceBetween' });
    d.segmented(bar, ['Today', 'This week', 'This month', 'Custom'], 0);
    d.tabs(bar, ['All branches', 'Cainta', 'Quezon City', 'San Mateo'], 0, { w: 'hug' });

    const k = d.row(c, { w: 'fill', gap: 16 });
    d.kpi(k, 'Sales today', '₱ 412,800', { delta: '+12% vs last Saturday', spark: [3, 5, 4, 6, 7, 6, 9], iconName: 'banknotes' });
    d.kpi(k, 'Units in shop', '22', { delta: '6 ready for pick-up', spark: [8, 9, 11, 10, 12, 13, 14], iconName: 'truck' });
    d.kpi(k, 'New bookings', '22', { delta: '+5 vs last Saturday', spark: [10, 14, 12, 15, 18, 17, 22], iconName: 'calendar-days' });
    d.kpi(k, 'Open backjobs', '2', { delta: '1 waiting over 2 days', deltaUp: false, spark: [1, 2, 1, 3, 2, 2, 2], iconName: 'wrench-screwdriver' });
    d.kpi(k, 'Average rating', '4.9', { delta: '38 reviews this month', spark: [4.6, 4.7, 4.8, 4.8, 4.9, 4.9, 4.9], iconName: 'star' });

    const mid = d.row(c, { w: 'fill', gap: 16, cross: 'min' });
    const sales = d.card(mid, { title: 'Sales by branch', sub: 'Last 7 days', action: 'Open report', w: 'fill' });
    const sr = d.row(sales, { w: 'fill', gap: 28, cross: 'max' });
    d.bars(sr, [312, 368, 291, 335, 390, 431, 413], { w: 470, h: 170, labels: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], highlight: 6 });
    const leg = d.col(sr, { w: 'fill', gap: 12 });
    for (const [b, amt, share, col] of [['Cainta', '₱ 168,200', 41, C.accent], ['Quezon City', '₱ 142,500', 35, C.info], ['San Mateo', '₱ 102,100', 24, C.gold]]) {
      const r = d.col(leg, { w: 'fill', gap: 6 });
      const t = d.row(r, { w: 'fill', align: 'spaceBetween' });
      d.text(t, b, { size: 13, weight: 600 });
      d.text(t, amt, { size: 13, color: C.muted });
      const track = d.row(r, { w: 'fill', h: 6, radius: 3, fill: C.s3, gap: 0 });
      d.rect(track, { w: Math.round(share * 2.6), h: 6, radius: 3, fill: col });
    }

    const live = d.card(mid, { title: 'In the shop now', sub: 'Updated by head detailers', action: 'Operations board', actionTo: 'A2', w: 420 });
    for (const [b, counts] of [['Cainta', [2, 3, 1, 2]], ['Quezon City', [1, 4, 1, 3]], ['San Mateo', [1, 2, 1, 1]]]) {
      const r = d.col(live, { w: 'fill', gap: 8, pad: [0, 0, 10, 0], stroke: C.line, sides: [0, 0, 1, 0] });
      d.text(r, b, { size: 13, weight: 700 });
      const chips = d.row(r, { gap: 6, wrap: true, w: 'fill', crossGap: 6 });
      ['Arrived', 'In progress', 'Quality check', 'Ready'].forEach((st, i) => {
        if (counts[i]) {
          const ch = d.chip(chips, st);
          void ch;
          d.text(chips, `×${counts[i]}`, { size: 12, color: C.muted });
        }
      });
    }

    const low = d.row(c, { w: 'fill', gap: 16, cross: 'min' });
    const reps = d.card(low, { title: 'Sales reps', sub: 'This month', w: 'fill' });
    d.table(reps, [{ label: 'Rep', w: 'fill' }, { label: 'Branch', w: 100 }, { label: 'Booked', w: 60, align: 'right' }, { label: 'Sales', w: 96, align: 'right' }], [
      [(x) => { d.avatar(x, 'JC', { size: 28, color: C.accent }); d.text(x, 'Joanna Cruz', { size: 14, weight: 600 }); }, 'Cainta', '31', '₱ 612,000'],
      [(x) => { d.avatar(x, 'MB', { size: 28, color: C.info }); d.text(x, 'Mark Bautista', { size: 14, weight: 600 }); }, 'Quezon City', '22', '₱ 455,500'],
      [(x) => { d.avatar(x, 'RL', { size: 28, color: C.gold }); d.text(x, 'Rhea Lim', { size: 14, weight: 600 }); }, 'San Mateo', '12', '₱ 238,000'],
    ], { rowH: 52 });
    const top = d.card(low, { title: 'Top services', sub: 'This month, by sales', w: 270 });
    for (const [s, n] of [['Graphene coating', '₱ 640k'], ['PPF full body', '₱ 520k'], ['Nano ceramic tint', '₱ 210k'], ['Interior detailing', '₱ 96k']]) {
      const r = d.row(top, { w: 'fill', align: 'spaceBetween' });
      d.text(r, s, { size: 14 });
      d.text(r, n, { size: 14, weight: 700 });
    }
    const rev = d.card(low, { title: 'Latest reviews', action: 'Moderate', actionTo: 'A13', w: 290 });
    for (const [who, txt] of [['Andrea R.', 'Land Cruiser came back looking new. Updates on the app the whole time.'], ['Paolo D.', 'Clean tint work, done on the day they said.']]) {
      const r = d.col(rev, { w: 'fill', gap: 4 });
      const t = d.row(r, { gap: 6 });
      d.text(t, who, { size: 13, weight: 700 });
      d.text(t, '★★★★★', { size: 12, color: C.gold });
      d.text(r, txt, { size: 13, color: C.muted, w: 'fill', lh: '140%' });
    }
  }


  // ---------- A2 Operations board ----------
  {
    const c = d.adminShell('A2', 'Operations board', {
      sub: 'One queue per branch · Saturday, 21 Nov',
      covers: [10, 11, 13, 14],
      note: 'Head detailer moves the cards, no bays',
      actions: [{ label: 'Add walk-in', kind: 'secondary' }],
      branch: 'Cainta',
    });
    const bar = d.row(c, { w: 'fill', align: 'spaceBetween' });
    d.tabs(bar, [{ label: 'Cainta', count: 11 }, { label: 'Quezon City', count: 6 }, { label: 'San Mateo', count: 1 }], 0, { w: 'hug' });
    const nr = d.row(bar, { gap: 8, pad: [8, 12, 8, 12], radius: 10, fill: C.s2, stroke: C.line });
    d.icon(nr, 'information-circle', { size: 16, color: C.accent });
    d.text(nr, 'One queue per branch, head detailer moves the cards', { size: 13, color: C.muted });

    const kcard = (p, { car, year, model, type, plate, svc, est, arr, headsUp, dim }) => {
      const k = d.col(p, { w: 'fill', gap: 8, pad: 12, radius: 12, fill: C.s2, stroke: C.line, opacity: dim ? 0.6 : undefined, name: `Card ${model}` });
      const pic = d.frame(k, { dir: 'h', w: 'fill', h: 52, radius: 8, fill: C.s3, align: 'center', cross: 'center', clip: true, name: 'Car tile' });
      if (car) d.car(pic, car, { w: 120, h: 44 });
      else d.icon(pic, 'truck', { size: 24, color: C.faint });
      const t = d.col(k, { w: 'fill', gap: 2 });
      d.text(t, `${year} ${model}`, { size: 13, weight: 700, w: 'fill' });
      d.text(t, `${type}  ·  ${plate}`, { size: 12, color: C.muted, w: 'fill' });
      d.text(k, svc, { size: 12, color: C.text, w: 'fill' });
      const er = d.row(k, { gap: 6 });
      d.icon(er, 'clock', { size: 14, color: C.accent });
      d.text(er, est, { size: 12, weight: 600 });
      if (headsUp) {
        const h = d.row(k, { w: 'fill', pad: [6, 8, 6, 8], radius: 8, fill: C.goldSoft, gap: 6, cross: 'min' });
        d.icon(h, 'bell-alert', { size: 14, color: C.gold });
        d.text(h, 'Heads-up sent to sales', { size: 11, weight: 600, color: C.gold, w: 'fill' });
      }
      const f = d.row(k, { w: 'fill', gap: 6, pad: [8, 0, 0, 0], stroke: C.line, sides: [1, 0, 0, 0] });
      d.avatar(f, 'JR', { size: 22, color: C.accent });
      d.text(f, arr, { size: 11, color: C.faint, w: 'fill' });
      return k;
    };
    const cols = [
      ['Booked', [
        { year: 2023, model: 'Toyota Fortuner', type: 'SUV', plate: 'NBC 3317', svc: 'Nano ceramic tint', est: '1 day', arr: 'Arrives Mon 23 Nov' },
        { year: 2021, model: 'Honda Civic', type: 'Sedan', plate: 'NCQ 7782', svc: 'Exterior detailing', est: '1 day', arr: 'Arrives Mon 23 Nov' },
      ]],
      ['Arrived', [
        { car: 'g63', year: 2023, model: 'Mercedes-AMG G 63', type: 'SUV', plate: 'NGZ 6310', svc: 'Exterior detailing', est: 'Day 1 of 2', arr: 'Arrived 9:40 AM' },
        { car: 'mustang', year: 2022, model: 'Ford Mustang Dark Horse', type: 'Coupe', plate: 'NDW 2204', svc: 'PPF windshield', est: 'Day 1 of 1', arr: 'Arrived 10:25 AM' },
      ]],
      ['In progress', [
        { car: 'bmw', year: 2023, model: 'BMW 7 Series', type: 'Sedan', plate: 'NDA 4821', svc: 'Graphene coating, Interior detailing', est: 'Day 1 of 3', arr: 'Arrived 9:12 AM', to: 'A3' },
        { car: 'roma', year: 2022, model: 'Ferrari Roma', type: 'Coupe', plate: 'NFR 0822', svc: 'PPF full body', est: 'Day 3 of 4', arr: 'Arrived Thu 19 Nov' },
        { car: 'lc300', year: 2022, model: 'Toyota Land Cruiser 300', type: 'SUV', plate: 'NEB 1190', svc: 'Nano ceramic tint', est: 'Day 2 of 2', arr: 'Arrived Fri 20 Nov' },
      ]],
      ['Quality check', [
        { car: 'p911', year: 2024, model: 'Porsche 911 Carrera', type: 'Coupe', plate: 'NFT 911', svc: 'Self-healing graphene coating', est: 'Day 3 of 3', arr: 'Arrived Wed 18 Nov', headsUp: true },
      ]],
      ['Ready', [
        { car: 'rr', year: 2021, model: 'Range Rover', type: 'SUV', plate: 'NCM 5509', svc: 'Interior detailing', est: 'Done · leaves today', arr: 'Arrived Fri 20 Nov', headsUp: true },
        { car: 'lx600', year: 2023, model: 'Lexus LX 600', type: 'SUV', plate: 'NHL 6001', svc: 'Leather coating', est: 'Done · leaves Mon', arr: 'Arrived Thu 19 Nov', headsUp: true },
      ]],
      ['Exited', [
        { year: 2022, model: 'BMW X5', type: 'SUV', plate: 'NDD 0517', svc: 'PPF per panel', est: 'Done', arr: 'Exited 8:50 AM', dim: true },
      ]],
    ];
    const board = d.row(c, { w: 'fill', gap: 10, cross: 'min', name: 'Board' });
    for (const [st, cards] of cols) {
      const col = d.col(board, { w: 'fill', gap: 10, pad: 8, radius: 14, fill: '#ffffff05', stroke: C.line, name: `Column ${st}` });
      const hr = d.row(col, { w: 'fill', align: 'spaceBetween', pad: [2, 4, 2, 4] });
      d.chip(hr, st);
      d.text(hr, String(cards.length), { size: 12, weight: 700, color: C.muted });
      for (const cd of cards) {
        const k = kcard(col, cd);
        if (cd.to) d.link(k, cd.to);
        else if (st === 'In progress' || st === 'Quality check') d.link(k, 'A3');
      }
    }
  }

  // ---------- A3 Booking detail ----------
  {
    const c = d.adminShell('A3', 'Booking detail', {
      sub: 'Booking 826-0142 · Cainta',
      covers: [13, 15, 22],
      note: 'Operations sets the exit date, sales gets the heads-up',
      active: 'A2',
      h: 1050,
      branch: 'Cainta',
      actions: [{ label: 'Message customer', kind: 'secondary' }, { label: 'Update status', kind: 'primary' }],
    });
    backLink(c, 'Operations board', 'A2');
    const hd = d.row(c, { w: 'fill', gap: 16, align: 'spaceBetween' });
    d.unit(hd, { car: 'bmw', year: 2023, model: 'BMW 7 Series', type: 'Sedan', plate: 'NDA 4821', w: 520 });
    const hc = d.row(hd, { gap: 10 });
    d.tag(hc, 'Large size class', { color: C.gold, bg: C.goldSoft });
    d.chip(hc, 'In progress');

    const main = d.row(c, { w: 'fill', gap: 16, cross: 'min' });
    const left = d.col(main, { w: 'fill', gap: 16 });
    const right = d.col(main, { w: 360, gap: 16 });

    const st = d.card(left, { title: 'Progress', sub: 'Head detailer updates this, arrived and exit times are recorded' });
    d.stepper(st, 2, { times: ['16 Nov', '21 Nov · 9:12 AM', '21 Nov · 11:05 AM', '', '', 'Est. 24 Nov'] });
    const est = d.row(st, { w: 'fill', gap: 10, pad: [12, 14, 12, 14], radius: 10, fill: C.s2 });
    d.icon(est, 'calendar-days', { size: 18, color: C.accent });
    d.text(est, '3 days · ready by Tue 24 Nov', { size: 14, weight: 700 });
    d.text(est, 'Day 1 of 3 today', { size: 13, color: C.muted });

    const tl = d.card(left, { title: 'Timeline', sub: 'Set by operations. Sales and the next booking depend on it' });
    const fr = d.row(tl, { w: 'fill', gap: 14, cross: 'min' });
    d.field(fr, 'Expected exit date', 'Tue 24 Nov 2026', { kind: 'date' });
    d.field(fr, 'Day estimate', '3 days', { kind: 'select', w: 200 });
    const nr = d.row(tl, { w: 'fill', gap: 12, pad: [12, 14, 12, 14], radius: 10, fill: C.s2 });
    toggle(nr, true);
    const nt = d.col(nr, { w: 'fill', gap: 2 });
    d.text(nt, 'Notify sales 1 day before exit', { size: 14, weight: 600 });
    d.text(nt, 'Cainta sales get a heads-up on Mon 23 Nov', { size: 12, color: C.muted });
    const nx = d.row(tl, { w: 'fill', gap: 10, pad: [12, 14, 12, 14], radius: 10, fill: C.goldSoft });
    d.icon(nx, 'truck', { size: 18, color: C.gold });
    d.text(nx, 'Next unit can come in: Wed 25 Nov', { size: 14, weight: 700, color: C.gold });

    const act = d.card(left, { title: 'Activity log', sub: 'Who changed what, and when' });
    for (const [ini, who, what, when, col] of [
      ['JR', 'Jun Reyes', 'moved the unit to In progress', 'Today, 11:05 AM', C.accent],
      ['JR', 'Jun Reyes', 'marked the unit as Arrived', 'Today, 9:12 AM', C.accent],
      ['OW', 'Owner', 'set expected exit to Tue 24 Nov and the estimate to 3 days', 'Fri 20 Nov, 4:30 PM', C.gold],
      ['JC', 'Joanna Cruz', 'created the booking with Graphene coating and Interior detailing', 'Mon 16 Nov, 2:18 PM', C.info],
    ]) {
      const r = d.row(act, { w: 'fill', gap: 12, pad: [0, 0, 10, 0], stroke: C.line, sides: [0, 0, 1, 0] });
      d.avatar(r, ini, { size: 28, color: col });
      const t = d.row(r, { w: 'fill', gap: 6 });
      d.text(t, who, { size: 13, weight: 700 });
      d.text(t, what, { size: 13, color: C.muted, w: 'fill' });
      d.text(r, when, { size: 12, color: C.faint });
    }

    const cu = d.card(right, { title: 'Customer' });
    d.text(cu, 'Miguel Santos', { size: 15, weight: 700 });
    d.text(cu, '+63 917 555 0142  ·  miguel.santos@example.com', { size: 12, color: C.muted, w: 'fill' });
    const ol = d.text(cu, 'Open profile', { size: 13, weight: 600, color: C.accent });
    d.link(ol, 'A6');
    const sv = d.card(right, { title: 'Services and notes' });
    kv(sv, 'Graphene coating', '₱ 72,000');
    kv(sv, 'Interior detailing', '₱ 9,500');
    d.divider(sv);
    label(sv, 'Notes');
    d.text(sv, 'Extra care on the rear bumper scratches. Customer picks up with a driver, ask for a valid ID.', { size: 13, color: C.muted, w: 'fill', lh: '145%' });
  }

  // ---------- A4 Inbox ----------
  {
    const c = d.adminShell('A4', 'Inbox', {
      sub: 'Facebook pages, website chat and Instagram in one place',
      covers: [2, 6, 19],
      note: 'One tab per branch page, plus website and Instagram',
    });
    d.tabs(c, [{ label: 'Cainta · FB page', count: 4 }, { label: 'Quezon City · FB page', count: 3 }, { label: 'San Mateo · FB page', count: 2 }, { label: 'Website chat', count: 2 }, { label: 'Instagram', count: 1 }], 0);
    const panes = d.row(c, { w: 'fill', gap: 16, cross: 'min' });

    const list = d.card(panes, { w: 320, h: 716, pad: 12, gap: 4, title: 'Conversations', sub: '4 unread' });
    for (const [ini, name, msg, time, unread, on, col] of [
      ['MS', 'Miguel Santos', 'Great, can I book for Monday?', '10:42', 2, true, C.accent],
      ['CM', 'Carla Mendoza', 'Is the PPF still available this week?', '10:15', 1, false, C.ok],
      ['PD', 'Paolo Dizon', 'Can I move my booking to Monday?', 'Yesterday', 1, false, C.info],
      ['JC', 'Joaquin Cruz', 'Thank you, picked up the G 63 today', '9:30', 0, false, C.gold],
      ['BV', 'Bea Villanueva', 'Can you send photos of the Roma?', 'Yesterday', 0, false, C.accent],
      ['RS', 'Rico Salazar', 'Thanks! See you on Saturday', 'Thu', 0, false, C.info],
      ['DO', 'Dan Ocampo', 'How much is PPF for a Fortuner?', 'Thu', 0, false, C.gold],
      ['NG', 'Nina Garcia', 'Do you do home service in Antipolo?', 'Wed', 0, false, C.ok],
    ]) {
      const r = d.row(list, { w: 'fill', gap: 10, pad: 10, radius: 10, fill: on ? C.accentSoft : 'none', cross: 'min' });
      d.avatar(r, ini, { size: 38, color: col });
      const t = d.col(r, { w: 'fill', gap: 3 });
      const tr = d.row(t, { w: 'fill', align: 'spaceBetween' });
      d.text(tr, name, { size: 14, weight: unread ? 700 : 600 });
      d.text(tr, time, { size: 11, color: C.faint });
      const mr = d.row(t, { w: 'fill', gap: 6 });
      d.text(mr, msg, { size: 12, color: unread ? C.text : C.muted, w: 'fill', maxLines: 1 });
      if (unread) d.tag(mr, String(unread), { size: 11, color: C.onAccent, bg: C.accent });
    }

    const th = d.card(panes, { w: 'fill', h: 716, pad: 0, gap: 0, name: 'Thread' });
    const thh = d.row(th, { w: 'fill', pad: [14, 20, 14, 20], gap: 12, stroke: C.line, sides: [0, 0, 1, 0] });
    d.avatar(thh, 'MS', { size: 38 });
    const tt = d.col(thh, { w: 'fill', gap: 2 });
    d.text(tt, 'Miguel Santos', { size: 15, weight: 700 });
    d.text(tt, 'Messenger · Cainta page', { size: 12, color: C.muted });
    d.tag(thh, 'Existing customer', { color: C.ok, bg: C.okSoft });
    const msgs = d.col(th, { w: 'fill', h: 'fill', pad: 20, gap: 12 });
    d.text(msgs, 'Today', { size: 11, color: C.faint, w: 'fill', align: 'center' });
    const bubble = (s, mine, time) => {
      const r = d.row(msgs, { w: 'fill', align: mine ? 'max' : 'min' });
      const b = d.col(r, { pad: [10, 14, 10, 14], radius: 14, fill: mine ? C.accentSoft : C.s3, gap: 4, stroke: mine ? '#2ad3cb44' : undefined });
      d.text(b, s, { size: 13, w: Math.min(300, Math.round(s.length * 7.2)), lh: '140%' });
      d.text(b, time, { size: 10, color: C.faint });
    };
    bubble("Hi! I'd like to ask about graphene coating for my Land Cruiser. How long does it take?", false, '10:38');
    bubble('And is there a warranty?', false, '10:39');
    bubble('Hi Miguel! Graphene coating on a Land Cruiser 300 takes about 3 days. I will send the warranty details with your quote.', true, '10:41  Joanna Cruz');
    bubble('Great, can I book for Monday?', false, '10:42');
    const rep = d.col(th, { w: 'fill', pad: [12, 20, 16, 20], gap: 10, stroke: C.line, sides: [1, 0, 0, 0] });
    const qr = d.row(rep, { w: 'fill', gap: 8, wrap: true, crossGap: 8 });
    for (const q of ['Send price list', 'Ask for unit details', 'Booking link', 'Warranty info']) d.tag(qr, q, { color: C.text, bg: C.s3, iconName: 'bolt' });
    const rb = d.row(rep, { w: 'fill', gap: 10 });
    const box = d.row(rb, { w: 'fill', h: 46, pad: [0, 14, 0, 14], radius: 10, fill: C.s2, stroke: C.lineStrong, gap: 10 });
    d.text(box, 'Reply to Miguel…', { size: 14, color: C.faint, w: 'fill' });
    d.icon(box, 'paper-clip', { size: 18, color: C.muted });
    d.button(rb, 'Send', 'primary');

    const side = d.card(panes, { w: 300, h: 716, title: 'Customer' });
    const sr = d.row(side, { w: 'fill', gap: 12 });
    d.avatar(sr, 'MS', { size: 48 });
    const stt = d.col(sr, { gap: 2 });
    d.text(stt, 'Miguel Santos', { size: 16, weight: 700 });
    d.text(stt, 'Cainta · since Feb 2024', { size: 12, color: C.muted });
    d.divider(side);
    kv(side, 'Units saved', '3');
    kv(side, 'Last job', 'Today, in progress');
    kv(side, 'Total spent', '₱ 684,500');
    d.divider(side);
    label(side, 'Units');
    for (const [car, m, pl] of [['bmw', '2023 BMW 7 Series', 'NDA 4821'], ['lc300', '2022 Land Cruiser 300', 'NEB 1190'], ['p911', '2024 Porsche 911', 'NFT 911']]) {
      const u = d.row(side, { w: 'fill', gap: 10 });
      const pic = d.frame(u, { dir: 'h', w: 56, h: 32, radius: 6, fill: C.s2, align: 'center', cross: 'center', clip: true });
      d.car(pic, car, { w: 52, h: 24 });
      const ut = d.col(u, { gap: 1, w: 'fill' });
      d.text(ut, m, { size: 13, weight: 600, w: 'fill' });
      d.text(ut, pl, { size: 11, color: C.muted });
    }
    const sp = d.col(side, { w: 'fill', h: 'fill' });
    void sp;
    const op = d.button(side, 'Open profile', 'secondary');
    d.link(op, 'A6');
    d.button(side, 'Create booking', 'primary');
  }

  // ---------- A5 Customers (CRM) ----------
  {
    const c = d.adminShell('A5', 'Customers', {
      sub: 'Every customer and the units they saved',
      covers: [8],
      actions: [{ label: 'Export', kind: 'secondary' }, { label: 'Add customer', kind: 'primary' }],
    });
    const k = d.row(c, { w: 'fill', gap: 16 });
    d.kpi(k, 'Customers', '412', { delta: '+18 this month', spark: [380, 386, 391, 397, 402, 408, 412], iconName: 'user-group' });
    d.kpi(k, 'Repeat customers', '38%', { delta: 'Booked 2 or more times', spark: [31, 33, 34, 35, 36, 37, 38], iconName: 'arrow-path' });
    d.kpi(k, 'Units saved', '587', { delta: '12 waiting for size review', deltaUp: false, spark: [520, 531, 544, 560, 571, 580, 587], iconName: 'truck' });
    d.kpi(k, 'Used a referral code', '64', { delta: '9 this month', spark: [40, 45, 49, 54, 58, 61, 64], iconName: 'gift' });

    const f = d.row(c, { w: 'fill', gap: 12, cross: 'min' });
    d.field(f, '', 'Search name, plate or phone', { placeholder: true, iconName: 'magnifying-glass', w: 380, h: 42 });
    d.field(f, '', 'All branches', { kind: 'select', w: 190, h: 42 });
    d.field(f, '', 'Last visit: any time', { kind: 'select', w: 210, h: 42 });

    const card = d.card(c, { pad: 0, gap: 0, name: 'Customers table' });
    const rows = [
      ['MS', 'Miguel Santos', C.accent, '3', 'Graphene coating · today', 'Cainta', '₱ 684,500', 'In progress'],
      ['AR', 'Andrea Reyes', C.gold, '1', 'Interior detailing · 14 Nov', 'Quezon City', '₱ 118,000', 'Ready'],
      ['PD', 'Paolo Dizon', C.info, '1', 'PPF windshield · 11 Nov', 'Cainta', '₱ 86,500', 'Active'],
      ['CM', 'Carla Mendoza', C.ok, '2', 'Interior detailing · 8 Nov', 'Cainta', '₱ 214,000', 'Active'],
      ['JC', 'Joaquin Cruz', C.gold, '1', 'Exterior detailing · 21 Nov', 'San Mateo', '₱ 342,000', 'Arrived'],
      ['BV', 'Bea Villanueva', C.accent, '1', 'PPF full body · 19 Nov', 'San Mateo', '₱ 396,000', 'In progress'],
    ];
    d.table(card, [
      { label: 'Customer', w: 'fill' }, { label: 'Units', w: 60 }, { label: 'Last service', w: 300 },
      { label: 'Branch', w: 120 }, { label: 'Total spent', w: 120, align: 'right' }, { label: 'Status', w: 120 },
    ], rows.map(([ini, name, col, units, last, br, total, st]) => [
      (x) => person(x, ini, name, col),
      units, last, br, total, (x) => d.chip(x, st),
    ]), { rowH: 68, onRow: (rr) => d.link(rr, 'A6') });
    const pg = d.row(c, { w: 'fill', align: 'spaceBetween' });
    d.text(pg, 'Showing 1 to 6 of 412 customers', { size: 13, color: C.muted });
    const pb = d.row(pg, { gap: 8 });
    d.button(pb, 'Previous', 'secondary', { size: 'sm' });
    d.button(pb, 'Next', 'secondary', { size: 'sm' });
  }

  // ---------- A6 Customer profile ----------
  {
    const c = d.adminShell('A6', 'Customer profile', {
      sub: 'Miguel Santos · Cainta',
      covers: [8, 20, 27],
      active: 'A5',
      actions: [{ label: 'Message', kind: 'secondary' }, { label: 'Create booking', kind: 'primary' }],
    });
    const hd = d.card(c, { pad: 20 });
    const hr = d.row(hd, { w: 'fill', gap: 18 });
    d.avatar(hr, 'MS', { size: 64 });
    const ht = d.col(hr, { w: 'fill', gap: 6 });
    d.text(ht, 'Miguel Santos', { size: 22, weight: 700 });
    const tr = d.row(ht, { gap: 18 });
    for (const [ic, s] of [['phone', '+63 917 555 0142'], ['envelope', 'miguel.santos@example.com'], ['map-pin', 'Cainta, Rizal'], ['calendar-days', 'Customer since Feb 2024']]) {
      const i = d.row(tr, { gap: 6 });
      d.icon(i, ic, { size: 16, color: C.faint });
      d.text(i, s, { size: 13, color: C.muted });
    }

    const main = d.row(c, { w: 'fill', gap: 16, cross: 'min' });
    const left = d.col(main, { w: 'fill', gap: 16 });
    const right = d.col(main, { w: 340, gap: 16 });

    const un = d.card(left, { title: 'Saved units', sub: 'Kept for repeat bookings. Admin assigns the size class used for pricing' });
    const ur = d.row(un, { w: 'fill', gap: 12 });
    for (const [car, year, model, type, plate, size] of [
      ['bmw', 2023, 'BMW 7 Series', 'Sedan', 'NDA 4821', 'Large'],
      ['lc300', 2022, 'Land Cruiser 300', 'SUV', 'NEB 1190', 'XL'],
      ['p911', 2024, 'Porsche 911 Carrera', 'Coupe', 'NFT 911', 'Medium'],
    ]) {
      const u = d.col(ur, { w: 'fill', gap: 10, pad: 12, radius: 12, fill: C.s2, stroke: C.line });
      const pic = d.frame(u, { dir: 'h', w: 'fill', h: 76, radius: 8, fill: C.s3, align: 'center', cross: 'center', clip: true });
      d.car(pic, car, { w: 160, h: 62 });
      const t = d.col(u, { w: 'fill', gap: 2 });
      d.text(t, `${year} ${model}`, { size: 14, weight: 700, w: 'fill' });
      d.text(t, `${type}  ·  ${plate}`, { size: 12, color: C.muted, w: 'fill' });
      const sr = d.row(u, { w: 'fill', align: 'spaceBetween' });
      d.text(sr, 'Size class', { size: 12, color: C.faint });
      d.tag(sr, size, { color: C.gold, bg: C.goldSoft });
    }

    const hist = d.card(left, { title: 'Service history', pad: 20, gap: 8 });
    d.table(hist, [{ label: 'Date', w: 100 }, { label: 'Unit', w: 'fill' }, { label: 'Service', w: 200 }, { label: 'Branch', w: 90 }, { label: 'Amount', w: 100, align: 'right' }], [
      ['21 Nov 2026', 'BMW 7 Series', 'Graphene coating', 'Cainta', '₱ 72,000'],
      ['14 Oct 2026', 'Land Cruiser 300', 'PPF windshield', 'Cainta', '₱ 38,000'],
      ['2 Aug 2026', 'Porsche 911', 'Interior detailing', 'Cainta', '₱ 9,500'],
      ['12 Mar 2025', 'BMW 7 Series', 'Self-healing graphene', 'Cainta', '₱ 96,000'],
      ['9 Feb 2024', 'Porsche 911', 'PPF full body', 'Cainta', '₱ 255,000'],
    ], { rowH: 50 });

    const wr = d.card(right, { title: 'Warranties', sub: '4 active, 1 expired', action: 'Open checker', actionTo: 'A7' });
    for (const [t, sv, st] of [['BMW 7 Series', 'Self-healing graphene', 'Active'], ['Porsche 911', 'PPF full body', 'Active'], ['Land Cruiser 300', 'PPF windshield', 'Active'], ['Porsche 911', 'Interior detailing', 'Expired']]) {
      const r = d.row(wr, { w: 'fill', align: 'spaceBetween' });
      const tx = d.col(r, { gap: 1 });
      d.text(tx, t, { size: 13, weight: 600 });
      d.text(tx, sv, { size: 12, color: C.muted });
      d.chip(r, st);
    }
    const rf = d.card(right, { title: 'Referral code' });
    const cb = d.row(rf, { w: 'fill', pad: [14, 16, 14, 16], radius: 12, fill: C.goldSoft, gap: 10 });
    d.icon(cb, 'gift', { size: 20, color: C.gold });
    d.text(cb, 'MIGUEL-826', { size: 20, weight: 700, color: C.gold, ls: '4%' });
    kv(rf, 'Used by', '3 people', { bold: true });
    kv(rf, 'Points earned', '300 points', { bold: true });
    d.text(rf, 'Friends who book with this code get a discount. Miguel earns points for each one.', { size: 12, color: C.muted, w: 'fill' });
    const nt = d.card(right, { title: 'Notes' });
    d.text(nt, 'Prefers Messenger updates. Always pick-up with a driver, bring ID. Likes the car handed back washed, no wax on trims.', { size: 13, color: C.muted, w: 'fill', lh: '145%' });
  }

  // ---------- A7 Warranty checker ----------
  {
    const c = d.adminShell('A7', 'Warranty checker', {
      sub: 'Look up any unit by plate or customer name',
      covers: [9],
    });
    const sc = d.card(c, { pad: 20, gap: 12 });
    const sr = d.row(sc, { w: 'fill', gap: 12 });
    d.field(sr, '', 'NDA 4821', { iconName: 'magnifying-glass', h: 56 });
    d.segmented(sr, ['Plate', 'Name'], 0);
    d.button(sr, 'Check warranty', 'primary');

    const res = d.row(c, { w: 'fill', gap: 16, pad: 16, radius: 14, fill: C.s2, stroke: C.line, align: 'spaceBetween' });
    d.unit(res, { car: 'bmw', year: 2023, model: 'BMW 7 Series', type: 'Sedan', plate: 'NDA 4821', w: 420 });
    const ow = d.col(res, { gap: 2 });
    d.text(ow, 'Owner', { size: 11, color: C.faint });
    const ol = d.text(ow, 'Miguel Santos', { size: 14, weight: 700, color: C.accent });
    d.link(ol, 'A6');
    const cov = d.col(res, { gap: 2 });
    d.text(cov, 'Coverage', { size: 11, color: C.faint });
    d.text(cov, '3 active · 1 expired', { size: 14, weight: 600 });
    d.button(res, 'Open backjob', 'primary');

    const tb = d.card(c, { title: 'Services done', pad: 20, gap: 8 });
    d.table(tb, [{ label: 'Service', w: 220 }, { label: 'Done', w: 120 }, { label: 'Covered until', w: 130 }, { label: 'Coverage', w: 110 }, { label: 'Backjob types covered', w: 'fill' }], [
      ['Self-healing graphene', '12 Mar 2025', '12 Mar 2028', (x) => d.chip(x, 'Active'), 'Coating touch-up, water-spot removal'],
      ['PPF full body', '9 Feb 2024', '9 Feb 2029', (x) => d.chip(x, 'Active'), 'Lifting edges, yellowing, peeling'],
      ['Nano ceramic tint', '9 Feb 2024', '9 Feb 2027', (x) => d.chip(x, 'Active'), 'Bubbling, discoloration'],
      ['Interior detailing', '2 Aug 2026', '2 Nov 2026', (x) => d.chip(x, 'Expired'), 'Stain or odor re-clean'],
    ], { rowH: 52 });

    const bh = d.card(c, { title: 'Backjob history', sub: 'Open a backjob to put the unit back in the branch queue as a Backjob job', pad: 20, gap: 8 });
    d.table(bh, [{ label: 'Date', w: 120 }, { label: 'Service', w: 220 }, { label: 'Issue', w: 'fill' }, { label: 'Branch', w: 110 }, { label: 'Handled by', w: 150 }, { label: 'Status', w: 120 }], [
      ['3 Jun 2025', 'Self-healing graphene', 'Water spots on the hood', 'Cainta', 'Jun Reyes', (x) => d.chip(x, 'Exited')],
      ['18 Oct 2025', 'Nano ceramic tint', 'Small bubble, rear window', 'Cainta', 'Jun Reyes', (x) => d.chip(x, 'Exited')],
    ], { rowH: 50 });
  }

  // ---------- A8 Availability ----------
  {
    const c = d.adminShell('A8', 'Availability', {
      sub: 'Daily unit cap and fully booked days, per branch',
      covers: [21],
      branch: 'Cainta',
    });
    const bar = d.row(c, { w: 'fill', align: 'spaceBetween' });
    d.tabs(bar, ['Cainta', 'Quezon City', 'San Mateo'], 0, { w: 'hug' });
    const mn = d.row(bar, { gap: 12 });
    d.icon(mn, 'chevron-left', { size: 18, color: C.muted });
    d.text(mn, 'November 2026', { size: 16, weight: 700 });
    d.icon(mn, 'chevron-right', { size: 18, color: C.muted });

    const body = d.row(c, { w: 'fill', gap: 16, cross: 'min' });
    const cal = d.card(body, { w: 'fill', pad: 20, gap: 8, title: 'Units booked per day', sub: 'Cainta · cap 8 units a day' });
    const W = 100;
    const hdr = d.row(cal, { w: 'fill', gap: 5 });
    for (const dn of ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']) d.text(hdr, dn, { size: 11, weight: 600, color: C.faint, upper: true, ls: '6%', w: W });
    const counts = [0, 5, 6, 4, 7, 6, 6, 0, 5, 7, 6, 6, 7, 5, 0, 6, 5, 7, 6, 7, 7, 0, 6, 8, 5, 0, 7, 8, 0, 3];
    for (let wk = 0; wk < 5; wk++) {
      const wr = d.row(cal, { w: 'fill', gap: 5, cross: 'min' });
      for (let dow = 0; dow < 7; dow++) {
        const day = wk * 7 + dow + 1;
        if (day > 30) {
          d.frame(wr, { w: W, h: 88, name: 'Empty' });
          continue;
        }
        const past = day < 21;
        const off = dow === 0;
        const closed = day === 26;
        const full = day === 24 || day === 28;
        const sel = day === 26;
        const cell = d.col(wr, {
          w: W, h: 88, pad: 10, gap: 6, radius: 10,
          fill: full ? C.dangerSoft : closed ? C.s3 : off ? 'none' : C.s2,
          stroke: sel ? C.accent : C.line,
          opacity: past ? 0.5 : undefined,
          name: `Day ${day}`,
        });
        const tr = d.row(cell, { w: 'fill', align: 'spaceBetween' });
        d.text(tr, String(day), { size: 14, weight: 700, color: day === 21 ? C.gold : off ? C.faint : C.text });
        if (day === 21) d.text(tr, 'Today', { size: 10, weight: 600, color: C.gold });
        if (off) {
          d.text(cell, 'Day off', { size: 11, color: C.faint });
          continue;
        }
        const n = closed ? 0 : counts[day - 1];
        d.text(cell, closed ? 'Closed' : `${n} / 8 units`, { size: 12, color: closed ? C.text : C.muted });
        if (full) {
          d.text(cell, 'Fully booked', { size: 11, weight: 700, color: C.danger });
        } else if (closed) {
          const lr = d.row(cell, { gap: 4 });
          d.icon(lr, 'lock-closed', { size: 12, color: C.muted });
          d.text(lr, 'By admin', { size: 11, color: C.muted });
        } else {
          const tk = d.row(cell, { w: 'fill', h: 4, radius: 2, fill: C.s4, gap: 0 });
          d.rect(tk, { w: Math.round((n / 8) * 78), h: 4, radius: 2, fill: C.accent });
        }
      }
    }
    const lg = d.row(cal, { w: 'fill', gap: 20, pad: [8, 0, 0, 0] });
    for (const [col, t] of [[C.danger, 'Fully booked (cap reached, automatic)'], [C.muted, 'Closed by admin (manual)'], [C.faint, 'Day off']]) {
      const r = d.row(lg, { gap: 6 });
      d.ellipse(r, { size: 8, fill: col });
      d.text(r, t, { size: 12, color: C.muted });
    }

    const side = d.col(body, { w: 340, gap: 16 });
    const dp = d.card(side, { title: 'Thu 26 Nov', sub: 'Cainta' });
    const cr = d.row(dp, { w: 'fill', gap: 8 });
    d.chip(cr, 'Fully booked');
    d.text(cr, 'Closed by admin', { size: 12, color: C.muted });
    d.field(dp, 'Daily cap (units)', '8', { hint: 'Booking closes on its own when this many units are booked' });
    const sw = d.row(dp, { w: 'fill', align: 'spaceBetween', pad: [12, 14, 12, 14], radius: 10, fill: C.s2 });
    d.text(sw, 'Mark as fully booked', { size: 14, weight: 600 });
    toggle(sw, true);
    d.field(dp, 'Reason', 'Staff training day', { hint: 'For staff only. Customers see "Fully booked".' });
    strip(dp, 'globe-alt', 'Applies to website and app booking', { color: C.info, bg: C.infoSoft });
    const br = d.row(dp, { w: 'fill', gap: 10 });
    d.button(br, 'Save day', 'primary');
    d.button(br, 'Reopen day', 'secondary');
    const ru = d.card(side, { title: 'How it works' });
    d.text(ru, 'A day closes by itself when booked units reach the daily cap. Use the switch to close a day sooner, for events or staff leave. Units already booked stay booked.', { size: 13, color: C.muted, w: 'fill', lh: '145%' });
  }

  // ---------- A9 Unit review ----------
  {
    const c = d.adminShell('A9', 'Unit review', {
      sub: 'Customers pick year, model and type. Set the size class used for pricing.',
      covers: [23, 24],
      actions: [{ label: 'Approve all suggested', kind: 'secondary' }],
    });
    const q = d.card(c, { title: 'Waiting for review', sub: '3 new units', pad: 20, gap: 8 });
    d.table(q, [
      { label: 'Unit', w: 'fill' }, { label: 'Customer', w: 170 }, { label: 'Submitted', w: 130 }, { label: 'Suggested', w: 100 }, { label: 'Set size', w: 160 }, { label: '', w: 100 },
    ], [
      [(x) => d.unit(x, { car: 'lx600', year: 2023, model: 'Lexus LX 600', type: 'SUV', compact: true }), 'Andrea Reyes', 'Today, 8:40 AM', (x) => d.tag(x, 'XL', { color: C.gold, bg: C.goldSoft }), (x) => sizeSel(x, 'XL', 150), (x) => d.button(x, 'Approve', 'primary', { size: 'sm' })],
      [(x) => d.unit(x, { car: 'rr', year: 2021, model: 'Range Rover', type: 'SUV', compact: true }), 'Carla Mendoza', 'Yesterday', (x) => d.tag(x, 'Large', { color: C.gold, bg: C.goldSoft }), (x) => sizeSel(x, 'Large', 150), (x) => d.button(x, 'Approve', 'primary', { size: 'sm' })],
      [(x) => d.unit(x, { car: 'mustang', year: 2022, model: 'Ford Mustang Dark Horse', type: 'Coupe', compact: true }), 'Paolo Dizon', 'Thu 19 Nov', (x) => d.tag(x, 'Medium', { color: C.gold, bg: C.goldSoft }), (x) => sizeSel(x, 'Medium', 150), (x) => d.button(x, 'Approve', 'primary', { size: 'sm' })],
    ], { rowH: 72 });

    const g = d.row(c, { w: 'fill', gap: 16, cross: 'min' });
    const guide = d.card(g, { title: 'Size class guide', sub: 'What the classes usually mean', w: 'fill' });
    const gr = d.row(guide, { w: 'fill', gap: 12 });
    for (const [s, t] of [['Small', 'Hatchbacks and small coupes'], ['Medium', 'Sedans and most coupes'], ['Large', 'Large sedans and mid-size SUVs'], ['XL', 'Full-size SUVs, pickups and vans']]) {
      const b = d.col(gr, { w: 'fill', gap: 6, pad: 14, radius: 12, fill: C.s2, stroke: C.line });
      d.tag(b, s, { color: C.gold, bg: C.goldSoft });
      d.text(b, t, { size: 13, color: C.muted, w: 'fill' });
    }
    const rc = d.card(c, { title: 'Recently approved', pad: 20, gap: 8 });
    d.table(rc, [{ label: 'Unit', w: 'fill' }, { label: 'Customer', w: 170 }, { label: 'Size class', w: 130 }, { label: 'Approved by', w: 160 }, { label: 'When', w: 130 }], [
      ['2022 Land Cruiser 300 · SUV', 'Miguel Santos', 'XL', 'Owner', 'Fri 20 Nov'],
      ['2023 Mercedes-AMG G 63 · SUV', 'Joaquin Cruz', 'XL', 'Owner', 'Thu 19 Nov'],
      ['2022 Ferrari Roma · Coupe', 'Bea Villanueva', 'Medium', 'Owner', 'Wed 18 Nov'],
    ], { rowH: 48 });
  }

  // ---------- A10 New transaction ----------
  {
    const c = d.adminShell('A10', 'New transaction', {
      sub: 'Walk-in or offline job. Saves to the branch queue.',
      covers: [26],
      h: 1080,
      actions: [{ label: 'Save as draft', kind: 'secondary' }],
    });
    const main = d.row(c, { w: 'fill', gap: 16, cross: 'min' });
    const form = d.col(main, { w: 'fill', gap: 16 });
    const sum = d.col(main, { w: 360, gap: 16 });

    const cu = d.card(form, { title: 'Customer and unit' });
    const r1 = d.row(cu, { w: 'fill', gap: 12, cross: 'max' });
    d.field(r1, 'Customer', 'Rico Salazar', { iconName: 'magnifying-glass' });
    d.tag(r1, 'New customer', { color: C.accent, bg: C.accentSoft, iconName: 'user-plus' });
    const r2 = d.row(cu, { w: 'fill', gap: 12, cross: 'min' });
    d.field(r2, 'Unit year', '2022', { kind: 'select', w: 120 });
    d.field(r2, 'Model', 'Mazda CX-5');
    d.field(r2, 'Type of unit', 'SUV', { kind: 'select', w: 160 });
    d.field(r2, 'Plate', 'NGH 4410', { w: 160 });

    const jb = d.card(form, { title: 'Job', sub: 'Pick the services done or booked' });
    const sv = d.row(jb, { w: 'fill', gap: 10, wrap: true, crossGap: 10 });
    const picked = ['Graphene coating', 'Interior detailing'];
    for (const s of ['Graphene coating', 'Self-healing graphene coating', 'Borophene coating', 'Leather coating', 'PPF full body', 'PPF per panel', 'PPF windshield', 'Nano ceramic tint', 'Interior detailing', 'Exterior detailing', 'Home service']) {
      const on = picked.includes(s);
      const it = d.row(sv, { w: 224, pad: [10, 12, 10, 12], radius: 10, gap: 10, fill: on ? C.accentSoft : C.s2, stroke: on ? '#2ad3cb55' : C.line });
      check(it, on);
      d.text(it, s, { size: 13, weight: on ? 600 : 400, color: on ? C.text : C.muted, w: 'fill' });
    }
    const r3 = d.row(jb, { w: 'fill', gap: 12, cross: 'min' });
    d.field(r3, 'Branch', 'Cainta', { kind: 'select' });
    d.field(r3, 'Day estimate', '3 days', { kind: 'select' });
    d.field(r3, 'Handled by', 'Joanna Cruz', { kind: 'select' });

    const pay = d.card(form, { title: 'Referral and payment' });
    const rr = d.row(pay, { w: 'fill', gap: 12, cross: 'max' });
    d.field(rr, 'Referral code', 'MIGUEL-826', { iconName: 'gift', w: 280 });
    const dl = d.row(rr, { w: 'fill', h: 46, pad: [0, 14, 0, 14], radius: 10, fill: C.okSoft, gap: 8 });
    d.icon(dl, 'check', { size: 16, color: C.ok });
    d.text(dl, 'New customer discount 10%, ₱ 8,150 off', { size: 13, weight: 600, color: C.ok });
    const pm = d.col(pay, { w: 'fill', gap: 6 });
    d.text(pm, 'Payment method', { size: 12, weight: 600, color: C.muted });
    d.segmented(pm, ['Cash', 'GCash', 'Maya', 'Bank transfer'], 1);
    const pr = d.row(pay, { w: 'fill', gap: 12, cross: 'min' });
    d.field(pr, 'Amount paid', '₱ 30,000', { w: 220, iconName: 'banknotes' });
    const up = d.col(pr, { w: 'fill', h: 96, gap: 6, align: 'center', cross: 'center', radius: 12, fill: C.s2, stroke: C.lineStrong, name: 'Receipt upload' });
    d.icon(up, 'camera', { size: 24, color: C.muted });
    d.text(up, 'Add a photo of the receipt', { size: 13, weight: 600 });
    d.text(up, 'JPG or PNG, up to 10 MB', { size: 11, color: C.faint });

    const sc = d.card(sum, { title: 'Summary' });
    d.text(sc, 'Rico Salazar', { size: 15, weight: 700 });
    d.text(sc, '2022 Mazda CX-5 · SUV · NGH 4410', { size: 12, color: C.muted, w: 'fill' });
    d.text(sc, 'Cainta · 3 days · handled by Joanna Cruz', { size: 12, color: C.muted, w: 'fill' });
    d.divider(sc);
    kv(sc, 'Graphene coating', '₱ 72,000');
    kv(sc, 'Interior detailing', '₱ 9,500');
    kv(sc, 'Referral MIGUEL-826 (10%)', '− ₱ 8,150', { color: C.ok });
    d.divider(sc);
    const tr = d.row(sc, { w: 'fill', align: 'spaceBetween' });
    d.text(tr, 'Total', { size: 15, weight: 700 });
    d.text(tr, '₱ 73,350', { size: 20, weight: 700 });
    kv(sc, 'Paid by GCash', '₱ 30,000');
    kv(sc, 'Balance', '₱ 43,350', { bold: true, color: C.gold });
    d.divider(sc);
    const rec = d.row(sc, { w: 'fill', gap: 8 });
    d.icon(rec, 'photo', { size: 16, color: C.faint });
    d.text(rec, 'Receipt photo not added yet', { size: 12, color: C.faint });
    d.button(sc, 'Save transaction', 'primary');
    d.text(sum, 'The unit joins the Cainta queue as Booked. Its size class is set later in Unit review.', { size: 12, color: C.faint, w: 'fill', lh: '140%' });
  }

  // ---------- A11 Users and roles ----------
  {
    const c = d.adminShell('A11', 'Users & roles', {
      sub: 'Accounts, branch access and permissions',
      covers: [1, 11, 12, 16, 18],
      actions: [{ label: 'Invite user', kind: 'primary' }],
    });
    const main = d.row(c, { w: 'fill', gap: 16, cross: 'min' });
    const left = d.col(main, { w: 'fill', gap: 16 });
    const right = d.col(main, { w: 400, gap: 16 });

    const tc = d.card(left, { pad: 0, gap: 0 });
    d.table(tc, [{ label: 'Name', w: 'fill' }, { label: 'Role', w: 130 }, { label: 'Branches', w: 110 }, { label: '2FA', w: 76 }, { label: 'Last active', w: 100 }], [
      [(x) => person(x, 'OW', 'Owner', C.gold), (x) => d.tag(x, 'Super admin', { color: C.gold, bg: C.goldSoft }), 'All branches', (x) => d.tag(x, 'On', { color: C.ok, bg: C.okSoft }), 'Now'],
      [(x) => person(x, 'JC', 'Joanna Cruz', C.accent), 'Sales rep', 'Cainta', (x) => d.tag(x, 'On', { color: C.ok, bg: C.okSoft }), '5 min ago'],
      [(x) => person(x, 'MB', 'Mark Bautista', C.info), 'Sales rep', 'Quezon City', (x) => d.tag(x, 'On', { color: C.ok, bg: C.okSoft }), 'Today'],
      [(x) => person(x, 'RL', 'Rhea Lim', C.gold), 'Sales rep', 'San Mateo', (x) => d.tag(x, 'Off', { color: C.danger, bg: C.dangerSoft }), 'Yesterday'],
      [(x) => person(x, 'JR', 'Jun Reyes', C.accent), 'Head detailer', 'Cainta', (x) => d.tag(x, 'On', { color: C.ok, bg: C.okSoft }), '12 min ago'],
      [(x) => person(x, 'PG', 'Paul Garcia', C.info), 'Head detailer', 'Quezon City', (x) => d.tag(x, 'On', { color: C.ok, bg: C.okSoft }), 'Today'],
      [(x) => person(x, 'ES', 'Ely Santos', C.gold), 'Head detailer', 'San Mateo', (x) => d.tag(x, 'Off', { color: C.danger, bg: C.dangerSoft }), '2 days ago'],
    ], { rowH: 54, onRow: (rr, ri) => { if (ri === 3) d.set(rr, { fill: '#2ad3cb12' }); } });

    const rc = d.card(left, { title: 'Roles', sub: 'Each account gets a role, then permissions can be adjusted per account' });
    const own = d.row(rc, { w: 'fill', gap: 12, pad: [12, 14, 12, 14], radius: 10, fill: C.goldSoft, cross: 'min' });
    d.icon(own, 'key', { size: 20, color: C.gold });
    const ot = d.col(own, { w: 'fill', gap: 2 });
    d.text(ot, 'Super admin', { size: 14, weight: 700, color: C.gold });
    d.text(ot, 'The owner. Master control of every branch, user, price, setting and report. Only the owner holds this role.', { size: 13, color: C.text, w: 'fill', lh: '140%' });
    for (const [r, t] of [
      ['Operations', 'Operations board, timelines, availability and unit review.'],
      ['Sales admin', 'Inbox, customers, referrals and sales reports across branches.'],
      ['Branch manager', 'Everything inside their own branch, except users and prices.'],
      ['Head detailer', 'Moves units through the queue and records arrived and exit times.'],
      ['Sales rep', 'Customers of their branch, inbox and the heads-up before exits.'],
    ]) {
      const rw = d.row(rc, { w: 'fill', gap: 16, pad: [0, 0, 8, 0], stroke: C.line, sides: [0, 0, 1, 0], cross: 'min' });
      d.text(rw, r, { size: 13, weight: 700, w: 130 });
      d.text(rw, t, { size: 13, color: C.muted, w: 'fill' });
    }

    const ed = d.card(right, { title: 'Rhea Lim', sub: 'rhea.lim@826auto.example' });
    d.field(ed, 'Role', 'Sales rep', { kind: 'select' });
    const bc = d.col(ed, { w: 'fill', gap: 8 });
    d.text(bc, 'Branch access', { size: 12, weight: 600, color: C.muted });
    const br = d.row(bc, { w: 'fill', gap: 16 });
    for (const [b, on] of [['Cainta', false], ['Quezon City', false], ['San Mateo', true]]) {
      const i = d.row(br, { gap: 8 });
      check(i, on);
      d.text(i, b, { size: 13, color: on ? C.text : C.muted });
    }
    d.divider(ed);
    for (const [grp, items] of [
      ['Operations', [['See operations board', true], ['Update unit status', false]]],
      ['Sales', [['See sales', true], ['Use inbox', true]]],
      ['Admin', [['Manage users', false], ['Manage prices', false], ['Manage availability', false]]],
    ]) {
      const g = d.col(ed, { w: 'fill', gap: 8 });
      label(g, grp);
      for (const [n, on] of items) {
        const r = d.row(g, { w: 'fill', align: 'spaceBetween' });
        d.text(r, n, { size: 13, color: on ? C.text : C.muted });
        toggle(r, on);
      }
    }
    d.divider(ed);
    const tf = d.row(ed, { w: 'fill', align: 'spaceBetween', pad: [10, 12, 10, 12], radius: 10, fill: C.s2 });
    const tt = d.col(tf, { gap: 1 });
    d.text(tt, 'Require 2FA', { size: 14, weight: 600 });
    d.text(tt, 'Code from an authenticator app at sign-in', { size: 12, color: C.muted });
    toggle(tf, true);
    const bb = d.row(ed, { w: 'fill', gap: 10 });
    d.button(bb, 'Save changes', 'primary');
    d.button(bb, 'Reset 2FA', 'danger');
  }

  // ---------- A12 2FA sign-in ----------
  {
    const s = d.screen('A12', '2FA sign-in', { w: 1440, h: 960, covers: [12], note: 'Second step after email and password, staff and admin' });
    d.set(s, { layout: 'vertical', mainAlign: 'center', crossAlign: 'center', padding: 48, gap: 28, fills: [{ type: 'linearGradient', stops: ['#14171a', '#060708'] }] });
    const brand = d.col(s, { gap: 10, cross: 'center' });
    d.img(brand, d.MARK, { w: 88, h: 60, name: '826 mark' });
    d.text(brand, 'Aesthetic & Protection', { size: 13, color: C.muted, ls: '8%', upper: true });
    const cd = d.col(s, { w: 440, pad: 36, gap: 22, radius: 20, cross: 'center', fills: [{ type: 'linearGradient', stops: [C.panelTop, C.panelBottom] }], stroke: C.lineStrong, shadow: true, name: 'Sign-in card' });
    const ic = d.frame(cd, { dir: 'h', w: 52, h: 52, radius: 999, fill: C.accentSoft, align: 'center', cross: 'center' });
    d.icon(ic, 'shield-check', { size: 26, color: C.accent });
    const tt = d.col(cd, { gap: 8, cross: 'center', w: 'fill' });
    d.text(tt, 'Two-step sign-in', { size: 22, display: true, align: 'center', w: 'fill' });
    d.text(tt, 'Enter the 6-digit code from your authenticator app', { size: 14, color: C.muted, align: 'center', w: 'fill', lh: '145%' });
    const codes = d.row(cd, { gap: 10 });
    ['4', '8', '2', '', '', ''].forEach((v, i) => {
      const b = d.frame(codes, { dir: 'h', w: 54, h: 66, radius: 12, fill: C.s2, stroke: i === 3 ? C.accent : C.lineStrong, align: 'center', cross: 'center', name: `Code ${i + 1}` });
      if (v) d.text(b, v, { size: 26, weight: 700 });
    });
    const tr = d.row(cd, { gap: 10, w: 'fill' });
    check(tr, true);
    d.text(tr, 'Trust this device for 30 days', { size: 14, color: C.text });
    const vb = d.button(cd, 'Verify', 'primary');
    d.fill(vb, 'h');
    d.link(vb, 'A1');
    d.text(cd, 'Use a backup code', { size: 14, weight: 600, color: C.accent });
    d.text(s, 'Lost your phone? Ask the owner to reset your 2FA.', { size: 13, color: C.faint });
  }

  // ---------- A13 Media and reviews ----------
  {
    const c = d.adminShell('A13', 'Media & reviews', {
      sub: 'What shows on the website: photos, YouTube videos and reviews',
      covers: [5],
      h: 1010,
    });
    d.tabs(c, [{ label: 'Photos', count: 48 }, { label: 'Videos', count: 4 }, { label: 'Reviews', count: 6 }], 0);
    const main = d.row(c, { w: 'fill', gap: 16, cross: 'min' });
    const left = d.col(main, { w: 'fill', gap: 16 });
    const right = d.col(main, { w: 400, gap: 16 });

    const ph = d.card(left, { pad: 20, gap: 14 });
    const phh = d.row(ph, { w: 'fill', align: 'spaceBetween' });
    const pt = d.col(phh, { gap: 2 });
    d.text(pt, 'Photos', { size: 15, weight: 700 });
    d.text(pt, 'Before and after shots for the gallery', { size: 12, color: C.muted });
    d.button(phh, 'Upload photos', 'primary');
    const grid = d.row(ph, { w: 'fill', gap: 12, wrap: true, crossGap: 12 });
    [['bmw', 'Before', '2023 BMW 7 Series'], ['bmw', 'After', '2023 BMW 7 Series'], ['p911', 'Before', '2024 Porsche 911'], ['p911', 'After', '2024 Porsche 911'],
      ['lc300', 'Before', 'Land Cruiser 300'], ['lc300', 'After', 'Land Cruiser 300'], ['roma', 'After', 'Ferrari Roma'], ['g63', 'After', 'Mercedes-AMG G 63']].forEach(([car, tg, cap]) => {
      const t = d.col(grid, { w: 154, gap: 8 });
      const pic = d.frame(t, { dir: 'h', w: 'fill', h: 96, radius: 10, fill: C.s2, stroke: C.line, align: 'center', cross: 'center', clip: true });
      d.car(pic, car, { w: 140, h: 70 });
      const r = d.row(t, { w: 'fill', gap: 8 });
      d.tag(r, tg, { size: 11, color: tg === 'After' ? C.accent : C.muted, bg: tg === 'After' ? C.accentSoft : C.s3 });
      d.text(r, cap, { size: 11, color: C.muted, w: 'fill', maxLines: 1 });
    });

    const vd = d.card(left, { pad: 20, gap: 14 });
    const vh = d.col(vd, { w: 'fill', gap: 2 });
    d.text(vh, 'Videos', { size: 15, weight: 700 });
    d.text(vh, 'Videos are YouTube links, no video files stored', { size: 12, color: C.muted });
    const vr = d.row(vd, { w: 'fill', gap: 10 });
    d.field(vr, '', 'Paste a YouTube link', { placeholder: true, iconName: 'link' });
    d.button(vr, 'Add video', 'secondary');
    for (const [t, u, on] of [
      ['Graphene coating on a Porsche 911, full process', 'youtube.com/watch?v=Zx81aQ', 'Home, Gallery'],
      ['PPF install on a Land Cruiser 300', 'youtube.com/watch?v=k3Pd0w', 'Gallery'],
      ['Cainta branch walk-through', 'youtube.com/watch?v=mT7vLs', 'Home'],
    ]) {
      const r = d.row(vd, { w: 'fill', gap: 14, pad: [10, 0, 0, 0], stroke: C.line, sides: [1, 0, 0, 0] });
      const th = d.frame(r, { dir: 'h', w: 128, h: 72, radius: 8, fill: C.s3, align: 'center', cross: 'center', name: 'Thumbnail' });
      d.icon(th, 'play', { size: 28, color: C.text });
      const tx = d.col(r, { w: 'fill', gap: 4 });
      d.text(tx, t, { size: 14, weight: 700, w: 'fill' });
      d.text(tx, u, { size: 12, color: C.faint });
      d.tag(tx, `Shown on: ${on}`, { size: 11 });
      d.button(r, 'Remove', 'ghost', { size: 'sm' });
    }

    const rv = d.card(right, { title: 'Reviews', sub: '3 waiting. Nothing shows until approved.', pad: 20, gap: 14 });
    for (const [who, txt, st, n] of [
      ['Andrea R.', 'Land Cruiser came back looking new. Updates on the app the whole time.', 'Needs review', 5],
      ['Paolo D.', 'Clean tint work, done on the day they said.', 'Needs review', 5],
      ['Carla M.', 'Interior smells and feels brand new. Staff were very helpful.', 'Needs review', 4],
      ['Joaquin C.', 'G 63 looks incredible after the PPF. Worth the wait.', 'Active', 5],
    ]) {
      const r = d.col(rv, { w: 'fill', gap: 8, pad: [12, 0, 0, 0], stroke: C.line, sides: [1, 0, 0, 0] });
      const t = d.row(r, { w: 'fill', align: 'spaceBetween' });
      const tl = d.row(t, { gap: 8 });
      d.text(tl, who, { size: 13, weight: 700 });
      d.text(tl, '★'.repeat(n) + '☆'.repeat(5 - n), { size: 12, color: C.gold });
      if (st === 'Active') d.tag(t, 'Shown on website', { size: 11, color: C.ok, bg: C.okSoft });
      else d.chip(t, st);
      d.text(r, txt, { size: 13, color: C.muted, w: 'fill', lh: '140%' });
      const ab = d.row(r, { gap: 8 });
      if (st === 'Active') d.button(ab, 'Hide', 'secondary', { size: 'sm' });
      else {
        d.button(ab, 'Approve', 'secondary', { size: 'sm' });
        d.button(ab, 'Hide', 'ghost', { size: 'sm' });
      }
    }
  }

  // ---------- A14 Referrals ----------
  {
    const c = d.adminShell('A14', 'Referrals', {
      sub: 'Every customer has a code. A new customer who uses it gets a discount, the code owner earns points.',
      covers: [20],
      h: 1060,
    });
    const how = d.row(c, { w: 'fill', gap: 16 });
    [['share', 'Customer shares a code', 'Each customer has their own code, like MIGUEL-826.'], ['calendar-days', 'Friend books with it', 'The code is entered when the friend books online or at the counter.'], ['gift', 'Discount and points', 'The friend gets the discount on their first job. The code owner earns points.']].forEach(([ic, t, s], i) => {
      const k = d.row(how, { w: 'fill', gap: 14, pad: 18, radius: 14, fill: C.s2, stroke: C.line, cross: 'min' });
      const n = d.frame(k, { dir: 'h', w: 40, h: 40, radius: 999, fill: C.accentSoft, align: 'center', cross: 'center' });
      d.icon(n, ic, { size: 20, color: C.accent });
      const tx = d.col(k, { w: 'fill', gap: 4 });
      d.text(tx, `${i + 1}. ${t}`, { size: 14, weight: 700 });
      d.text(tx, s, { size: 13, color: C.muted, w: 'fill', lh: '140%' });
    });

    const main = d.row(c, { w: 'fill', gap: 16, cross: 'min' });
    const st = d.card(main, { w: 360, title: 'Referral settings', sub: 'Applies to every code' });
    d.text(st, 'Discount for the new customer', { size: 12, weight: 700, color: C.faint, upper: true, ls: '6%' });
    const tp = d.col(st, { w: 'fill', gap: 6 });
    d.text(tp, 'Discount type', { size: 12, weight: 600, color: C.muted });
    d.segmented(tp, ['Percent (%)', 'Peso (₱)'], 0);
    d.field(st, 'Value', '10', { hint: '10% off the first job' });
    d.field(st, 'Max uses per code', '10', { hint: 'Leave empty for no limit' });
    const vs = d.col(st, { w: 'fill', gap: 6 });
    d.text(vs, 'Valid for services', { size: 12, weight: 600, color: C.muted });
    const chips = d.row(vs, { w: 'fill', gap: 6, wrap: true, crossGap: 6 });
    for (const [s, on] of [['Graphene coating', true], ['PPF full body', true], ['Nano ceramic tint', true], ['Interior detailing', true], ['Home service', false]]) d.tag(chips, s, { color: on ? C.accent : C.muted, bg: on ? C.accentSoft : C.s3 });
    d.divider(st);
    d.text(st, 'Points for the code owner', { size: 12, weight: 700, color: C.faint, upper: true, ls: '6%' });
    d.field(st, 'Points per referral', '100', { iconName: 'star', hint: 'Added to the code owner each time a friend uses the code' });
    const pw = d.col(st, { w: 'fill', gap: 6 });
    d.text(pw, 'Give points when', { size: 12, weight: 600, color: C.muted });
    d.segmented(pw, ['Friend books', 'Friend\'s job is done'], 1);
    d.button(st, 'Save settings', 'primary');

    const right = d.col(main, { w: 'fill', gap: 16 });
    const k = d.row(right, { w: 'fill', gap: 16 });
    d.kpi(k, 'Codes used', '64', { delta: '9 this month', spark: [40, 45, 49, 54, 58, 61, 64], iconName: 'gift' });
    d.kpi(k, 'Points given', '1,500', { delta: 'To 6 code owners', spark: [600, 800, 900, 1100, 1200, 1400, 1500], iconName: 'star' });
    d.kpi(k, 'Discounts given', '₱ 286,400', { delta: 'All time', spark: [120, 150, 180, 210, 240, 265, 286], iconName: 'banknotes' });
    const tc = d.card(right, { title: 'Codes', sub: 'People who used a code are counted on the owner of that code', pad: 20, gap: 8 });
    d.table(tc, [{ label: 'Code owner', w: 'fill' }, { label: 'Code', w: 116 }, { label: 'Used', w: 50, align: 'right' }, { label: 'Discount given', w: 120, align: 'right' }, { label: 'Points earned', w: 104, align: 'right' }], [
      [(x) => person(x, 'MS', 'Miguel Santos', C.accent), 'MIGUEL-826', '3', '₱ 22,400', '300'],
      [(x) => person(x, 'AR', 'Andrea Reyes', C.gold), 'ANDREA-826', '5', '₱ 41,250', '500'],
      [(x) => person(x, 'PD', 'Paolo Dizon', C.info), 'PAOLO-826', '2', '₱ 14,800', '200'],
      [(x) => person(x, 'CM', 'Carla Mendoza', C.ok), 'CARLA-826', '1', '₱ 6,200', '100'],
      [(x) => person(x, 'JC', 'Joaquin Cruz', C.gold), 'JOAQUIN-826', '4', '₱ 36,900', '400'],
      [(x) => person(x, 'BV', 'Bea Villanueva', C.accent), 'BEA-826', '0', '₱ 0', '0'],
    ], { rowH: 54 });
  }

  d.flow('A1', 'Admin website');
}
