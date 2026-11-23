/*
 * Response shapes of the 826 staff API (/api/staff/*), from the phase 2 contract.
 * The app never invents facts: money, contact and photos only show when the
 * server sent them, and the server only sends what the person's role may see.
 */
export type Stage = 'requested' | 'awaiting_payment' | 'payment_review' | 'confirmed' | 'arrived' | 'in_progress' | 'quality_check' | 'ready' | 'exited' | 'expired' | 'cancelled' | 'declined' | 'no_show';
/** The stages a unit moves to from the queue */
export type MoveTo = 'arrived' | 'in_progress' | 'quality_check' | 'ready' | 'exited';

export type TabKey = 'queue' | 'today' | 'clients' | 'inbox' | 'overview' | 'system' | 'me';
export type Branch = { id: number; name: string };

export type StaffUser = {
    id: number;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    isOwner: boolean;
    areas: string[];
    branches: Branch[];
    /** The ordered app tabs the server decided from the areas (at most 5) */
    tabs: string[];
    /** Tabs that did not fit the bar (Clients, when there are more than five): reached from Me */
    overflow?: string[];
    /** The team app the token was issued for (absent for the legacy 826 Team app) */
    app?: string;
};

export type SignedIn = { token: string; expiresAt?: string; user: StaffUser };
export type TwoFactorChallenge = { twoFactor: { challenge: string; expiresInSeconds?: number } };
export type SignInResponse = SignedIn | TwoFactorChallenge;

export type Vehicle = { id: number | null; year: number | null; model: string | null; type: string | null; typeLabel: string | null; plate: string | null; size: string | null };

/** The one button a unit's card offers, as the server words it. `confirm` is for what can't be taken back. */
export type NextStep = { to: MoveTo; label: string; confirm: boolean };

/** A unit on the queue board or one of Today's lists (StaffViews::card) */
export type Card = {
    id: number;
    reference: string | null;
    kind: string;
    stage: Stage;
    /** The customer's first name only */
    name: string;
    unit: string | null;
    vehicle: Vehicle;
    services: string[];
    servicesLabel: string | null;
    dropOffOn: string | null;
    readyOn: string | null;
    readyLabel: string | null;
    dayNumber: number | null;
    totalDays: number | null;
    /** "Day 1 of 3" */
    dayLabel: string | null;
    home: boolean;
    needsSize: boolean;
    late: boolean;
    note: string | null;
    next: NextStep | null;
    canAdvance: boolean;
    arrivedAt: string | null;
};

export type QueueColumn = { key: string; label: string; count: number; cards: Card[] };

export type Queue = {
    branch: Branch | null;
    branches: Branch[];
    date: string;
    heading: string;
    isToday: boolean;
    dropOffs: { cap: number; booked: number; state: string } | null;
    columns: QueueColumn[];
    counts: Record<string, number> | null;
    needsSize: number | null;
    homeRequests: number | null;
};

export type TimelineStep = { key: string; label: string; at: string | null; done: boolean; current: boolean };
export type HistoryEvent = { id: number; kind: string; from: string | null; to: string | null; note: string | null; by: string | null; at: string; line: string };
export type UnitPhoto = { id: number; url: string; thumb: string; by: string | null; at: string };

export type UnitMoney = {
    priced: boolean;
    total: number | null;
    subtotal: number | null;
    discount: number | null;
    amountDue: number | null;
    amountPaid: number | null;
    paidAt: string | null;
    paymentMethod: string | null;
    paymentSubmittedAt: string | null;
    rejectedReason: string | null;
    proofUrl: string | null;
};

export type UnitDetail = {
    id: number;
    reference: string | null;
    kind: string;
    status: string;
    stage: Stage;
    statusLabel: string;
    branch: Branch | null;
    /** Name, phone, email and the like are null without customers.view or bookings.sales */
    customer: { firstName: string; name: string | null; phone: string | null; email: string | null; guestId: number | null; address: string | null; area: string | null };
    customerMessage: string | null;
    vehicle: Vehicle & { label: string | null };
    services: { name: string; category: string | null; estimateDays: number | null }[];
    dropOffOn: string | null;
    readyOn: string | null;
    readyLabel: string | null;
    dayNumber: number | null;
    totalDays: number | null;
    dayLabel: string | null;
    late: boolean;
    needsSize: boolean;
    next: NextStep | null;
    can: { advance: boolean; changeReadyDate: boolean; addNote: boolean; addPhoto: boolean; setSize: boolean; reviewPayment: boolean };
    timeline: TimelineStep[];
    photos: UnitPhoto[];
    /** Newest first */
    history: HistoryEvent[];
    /** Only with bookings.sales; otherwise null */
    money: UnitMoney | null;
};

export type PaymentCard = Card & { payment: { method: string | null; amountDue: number | null; submittedAt: string | null; proofUrl: string | null } };
export type HomeCard = Card & { preferredDate: string | null; preferredWindow: string | null; address: string | null; area: string | null };
export type Today = { branch: Branch | null; date: string; dropOffsToday: Card[]; needsSizeReview: Card[]; paymentsToCheck: PaymentCard[]; homeRequests: HomeCard[] };
export type VehicleSize = { id: number; name: string; examples: string | null };

export type CustomerRow = { id: number; name: string; phone: string | null; email: string | null; branch: string | null; unitsCount: number; bookingsCount: number; lastVisitOn: string | null };
export type CustomerPage = { data: CustomerRow[]; page: number; perPage: number; total: number; hasMore: boolean };
export type CustomerDetail = {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    branch: Branch | null;
    source: string | null;
    sourceLabel: string | null;
    referralCode: string | null;
    pointsBalance: number | null;
    notes: string | null;
    units: { id: number; label: string; plate: string | null; size: string | null; historyCount: number }[];
    bookings: { id: number; reference: string | null; status: string; statusLabel: string; branch: string | null; unit: string | null; servicesLabel: string | null; dropOffOn: string | null; readyOn: string | null; total: number | null }[];
    /** The Messenger chat linked to this customer, when there is one this person may see */
    messenger: { conversationId: number; branchId: number | null } | null;
    can: { edit: boolean };
};

/** key is a branch id as text, or "all" */
export type InboxTab = { key: string; label: string; unread: number };
export type ConversationRow = { id: number; name: string; avatar: string | null; branch: Branch | null; preview: string | null; lastMessageAt: string | null; unread: number; done: boolean; sample: boolean; linkedCustomer: boolean; /** Only heads-up and team (internal) threads carry these */ channel?: 'headsup' | 'internal'; internal?: boolean; booking?: { id: number; reference: string | null } | null };
export type Inbox = { branch: string; filter: string; showsAll: boolean; tabs: InboxTab[]; totalUnread: number; notice: string | null; conversations: ConversationRow[] };
export type Message = { id: number; direction: 'in' | 'out'; body: string | null; attachments: unknown[]; senderName: string | null; failed: boolean; error: string | null; sentAt: string };
export type Thread = {
    conversation: ConversationRow & { assignedTo: { id: number; name: string } | null; customer: { id: number; name: string } | null; pastReplyWindow: boolean; windowHours: number; savesLocallyOnly: boolean };
    messages: Message[];
    hasOlder: boolean;
};
export type ReplyResult = { message: Message; conversation: ConversationRow; savedOnly: boolean };
export type QuickReply = { key: string; label: string; text: string };

export type Overview = {
    period: { key: string; label: string; from: string; to: string };
    branch: Branch | null;
    branches: Branch[];
    sales: number | null;
    collected: number | null;
    averageTicket: number | null;
    unitsDone: number | null;
    turnaround: { toReady: number | null; toExit: number | null } | null;
    branchesTable: { name: string; units: number; sales: number; average: number | null; noShowRate: number | null; toReady: number | null; toExit: number | null }[] | null;
    topReps: { name: string; role?: string; jobs?: number; sales?: number; moves?: number; referrals?: number }[] | null;
    backjobsOpen: number | null;
    reviewRating: { rating: number | null; count: number } | null;
    attention: { size: number | null; screenshots: number | null; home: number | null; reviews: number | null; chats: number | null; late: number | null };
};
export type Period = 'today' | '7d' | '30d' | 'month';

export type AppRelease = { version: string | null; versionCode: number; url: string | null; sha256: string | null; notes: string | null; mandatory: boolean };
export type UploadFile = { uri: string; name: string; type: string };

/** GET /api/staff/system (826 System app): booleans and versions only, never a secret */
export type SystemInfo = {
    health: Record<string, boolean>;
    customerApp: { version: string; versionCode: number; publishedAt: string | null } | null;
    teamApps: { app: string; label: string; version: string | null }[];
    security: { requireTwoFactor: boolean; passkeysAllowed: boolean };
};
