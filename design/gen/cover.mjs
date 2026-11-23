// Page 0: cover + feedback map (every owner request → the screens that answer it).
export const FEEDBACK = [
  ['App features depending on department (phase 2)', 'S1–S7, A11'],
  ['Centralize communication every page per tab', 'A4, S5'],
  ['Owner master dashboard for sales, performance and everything', 'A1, S7'],
  ['Hostinger', 'Hosting: deploy target, no screen'],
  ['Videos, pictures and reviews on the website (videos as YouTube links)', 'A13, C7, W2'],
  ['Admin inbox', 'A4'],
  ['iOS and APK app for 826', 'Staff app S1–S7, Customer app C1–C7'],
  ['CRM type', 'A5, A6'],
  ['General warranty checker: what services, what type of backjob', 'A7, C5'],
  ['Head detailer oversees units and updates the queue', 'A2, S2, S3'],
  ['Operations, sales admin, branch manager see operations and sales', 'A11, S6'],
  ['2FA', 'A12, S1, A11'],
  ['Bookings: arrived / exit', 'A2, A3, S3, C3'],
  ['Remove bay 1, bay 2, bay 3', 'A2 (one queue per branch, no bays)'],
  ['Operations sets a timeline to heads-up sales when a unit changes', 'A3, S3, S4'],
  ['1 super admin, master control (website)', 'A11, A1'],
  ['Designated sales rep', 'A6, A3, S4'],
  ['Per account admin control (website)', 'A11'],
  ['FB page per branch (centralized comms)', 'A4'],
  ['Reward system: referral code (friend gets a discount, code owner earns points)', 'A14, A6, C6, C1, C2, W1'],
  ['Admin control of fully booked days', 'A8, W1'],
  ['No time estimate, day estimate only', 'A3, S3, C2, W1'],
  ['Unit size reviewed by admin', 'A9'],
  ['No size option: year model + type of unit only', 'C2, W1, A9'],
  ['User friendly', 'Every screen: one main action each, plain labels'],
  ['Client has a manual transaction form', 'A10'],
  ['Customer unit information kept for repeat visits', 'A6, C4, C2'],
];

export default function cover(d) {
  const { C } = d;
  d.page('Cover & feedback map', { cols: 1, w: 1920, h: 1500 });
  const s = d.screen('00', 'Feedback map', { w: 1920, h: 1500 });
  d.set(s, { layout: 'horizontal', padding: 80, gap: 80 });

  const left = d.col(s, { w: 560, h: 'fill', gap: 28, name: 'Intro' });
  d.img(left, d.MARK, { w: 150, h: 100, name: '826 mark' });
  d.text(left, 'Phase 2', { size: 72, display: true, w: 'fill' });
  d.text(left, 'Admin, staff app and customer app for 826 Auto Aesthetic & Protection.', { size: 22, weight: 600, w: 'fill', lh: '135%' });
  d.text(left, 'Each request from the owner is on the right, with the screens that answer it. Screen codes: A = admin website, S = staff app, C = customer app, W = public website.', { size: 16, color: C.muted, w: 'fill', lh: '150%' });
  const legend = d.col(left, { gap: 12, w: 'fill' });
  for (const [code, label, n] of [['A1–A14', 'Admin website', '14 screens'], ['S1–S7', 'Staff app (iOS + Android), by department', '7 screens'], ['C1–C7', 'Customer app (iOS + Android)', '7 screens'], ['W1–W2', 'Public website', '2 screens']]) {
    const r = d.row(legend, { w: 'fill', gap: 14, pad: [14, 16, 14, 16], radius: 12, fill: C.s2, stroke: C.line });
    d.text(r, code, { size: 15, weight: 800, color: C.accent, w: 90 });
    d.text(r, label, { size: 15, weight: 600, w: 'fill' });
    d.text(r, n, { size: 13, color: C.muted });
  }
  const q = d.card(left, { title: 'Open questions for the owner', gap: 10, name: 'Open questions' });
  for (const line of [
    'Referral points: what can customers use them for, and when do they expire?',
    'Discount amount, prices and day estimates per service (shown as sample numbers).',
    'Warranty terms per service and which backjobs each one covers.',
    'Names and FB pages of the other 3 branches.',
    'Reservation fee: is it deducted from the final bill?',
  ]) {
    const r = d.row(q, { w: 'fill', gap: 10, cross: 'min' });
    d.icon(r, 'question-mark-circle', { size: 18, color: C.gold });
    d.text(r, line, { size: 14, color: C.muted, w: 'fill', lh: '145%' });
  }
  d.text(left, 'All names, plates and numbers in this file are samples.', { size: 13, color: C.faint });

  const right = d.col(s, { w: 'fill', gap: 0, name: 'Feedback list' });
  const hr = d.row(right, { w: 'fill', h: 44, gap: 20, pad: [0, 18, 0, 18], stroke: C.line, sides: [0, 0, 1, 0] });
  d.text(hr, '#', { size: 12, weight: 600, color: C.faint, w: 34 });
  d.text(hr, 'Owner request', { size: 12, weight: 600, color: C.faint, upper: true, ls: '6%', w: 'fill' });
  d.text(hr, 'Where it is', { size: 12, weight: 600, color: C.faint, upper: true, ls: '6%', w: 380 });
  FEEDBACK.forEach(([req, where], i) => {
    const r = d.row(right, { w: 'fill', h: 46, gap: 20, pad: [0, 18, 0, 18], stroke: C.line, sides: [0, 0, 1, 0], fill: i % 2 ? '#ffffff04' : 'none', name: `Item ${i + 1}` });
    d.text(r, String(i + 1).padStart(2, '0'), { size: 14, weight: 700, color: C.accent, w: 34 });
    d.text(r, req, { size: 15, weight: 600, w: 'fill', maxLines: 1 });
    d.text(r, where, { size: 14, color: C.muted, w: 380, maxLines: 1 });
  });
}
