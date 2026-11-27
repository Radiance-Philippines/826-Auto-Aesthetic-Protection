/** A picture from the image pipeline: a medium file, its sizes and a thumbnail */
export type Image = {
    src: string;
    srcset: string | null;
    thumb: string;
};

export type Settings = {
    tagline: string | null;
    intro: string | null;
    hero: (Image & { full: string }) | null;
    phone: string | null;
    /** "+639488042284", for tel: and sms: links */
    phoneHref: string | null;
    email: string | null;
    facebook: string | null;
    instagram: string | null;
    messenger: string | null;
};

/** The signed-in customer: their own account (email and password), Google, or added by the team */
export type SignInMethod = 'password' | 'google' | 'apple' | null;

export type Guest = {
    name: string;
    /** Empty for a customer the team added without a Google account */
    email: string | null;
    avatar: string | null;
    signInMethod: SignInMethod;
};

export type AuthData = {
    googleEnabled: boolean;
    /** Accounts with an email and a password work here (the mailbox is set up, or this is the demo) */
    emailEnabled: boolean;
    /** The demo: no email is sent, sign-up verifies the account at once */
    emailAutoVerify: boolean;
    guest: Guest | null;
    /** /auth/google, takes ?return=<site-relative path> */
    signInUrl: string;
    /** POST with the CSRF header, 204 */
    signOutUrl: string;
    /** Local development only, while Google isn't set up: signs in a test customer */
    localSignInUrl: string | null;
};

/** The kinds of unit a customer can pick ("sedan", "Sedan") */
export type VehicleType = {
    key: string;
    label: string;
};

/** How long a job keeps the unit: "about 3 days" */
export type Estimate = {
    days: number;
    label: string;
};

export type Service = {
    id: number;
    name: string;
    slug: string;
    summary: string | null;
    description: string | null;
    inclusions: string[];
    image: Image | null;
    homeServiceOk: boolean;
    /** Days at the shop; null until the owner fills it in. There is no price: it is set after 826 reviews the unit */
    estimateDays: number | null;
};

export type Category = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    services: Service[];
};

export type BranchHours = {
    /** ISO weekday: 1 = Monday … 7 = Sunday */
    day: number;
    label: string;
    /** "08:00", null when closed */
    open: string | null;
    close: string | null;
};

export type BranchMap = {
    google: string | null;
    waze: string | null;
    embed: string | null;
};

export type Branch = {
    id: number;
    slug: string;
    name: string;
    status: 'open' | 'coming_soon';
    city: string | null;
    address: string | null;
    phone: string | null;
    phoneHref: string | null;
    messenger: string | null;
    facebook: string | null;
    map: BranchMap | null;
    hours: BranchHours[];
    /** The last day it takes bookings and home-service requests for ("2027-02-28") */
    lastDay: string;
    /** Takes online reservations now */
    bookable: boolean;
    /** The services that can be booked here */
    serviceIds: number[];
    homeService: { offered: boolean; areas: string[] };
};

export type HomeWindow = 'morning' | 'afternoon' | 'any';

export type BookingSettings = {
    cancelCutoffHours: number;
    rescheduleEnabled: boolean;
    rescheduleCutoffHours: number;
    maxReschedules: number;
    policy: string | null;
    fee: { enabled: boolean; label: string | null; holdMinutes: number };
    windows: Record<HomeWindow, string>;
    /** Referral codes: on only when the owner has set a discount */
    referral: { enabled: boolean; discountLabel: string | null };
};

/** A YouTube video (a link, nothing uploaded) */
export type VideoItem = { id: number; title: string; caption: string | null; youtubeId: string; thumbnail: string; url: string };

/** An approved customer review (window.APP826.reviews) */
export type Review = {
    id: number;
    /** "Maria S." */
    name: string;
    rating: number;
    body: string;
    photo: Image | null;
    /** The first service of the booking */
    service: string | null;
    branch: string | null;
    /** "2026-11-20" */
    date: string;
};

/** What the customer sees of their own review */
export type MyReview = {
    rating: number;
    body: string;
    status: 'pending' | 'approved' | 'rejected';
    photo: string | null;
};

export type PhotoCategory = string;

export type Photo = Image & {
    id: number;
    caption: string | null;
    category: PhotoCategory;
    width: number | null;
    height: number | null;
    isFeatured: boolean;
    full: string;
};

export type SiteData = {
    name: string;
    shortName: string;
    /** The "826" mark alone: reads on dark and light */
    logo: string | null;
    /** The whole logo, with black text: light backgrounds only */
    logoFull: string | null;
    /** The folder the site is served from ("" at a domain's root, "/demo/826") */
    base: string;
    /** A preview copy: sample schedules, nothing is saved */
    demo: boolean;
    /** The demo, opened with the team's PIN: bookings are kept */
    demoSaves: boolean;
    settings: Settings;
    auth: AuthData;
    catalog: { categories: Category[] };
    vehicleTypes: VehicleType[];
    branches: Branch[];
    booking: BookingSettings;
    photos: Photo[];
    videos: VideoItem[];
    /** Approved reviews only: featured first, then newest (max 24) */
    reviews: Review[];
    photoCategories: Record<string, string>;
};

export type DayState = 'open' | 'full' | 'closed';

/** GET /api/availability */
export type MonthAvailability = {
    /** "2026-11" */
    month: string;
    /** The first and last days that can be booked */
    first: string;
    last: string;
    days: Record<string, DayState>;
    estimate: Estimate | null;
};

export type Stage =
    | 'requested'
    | 'awaiting_payment'
    | 'payment_review'
    | 'confirmed'
    | 'arrived'
    | 'in_progress'
    | 'quality_check'
    | 'ready'
    | 'exited'
    | 'expired'
    | 'cancelled'
    | 'declined'
    | 'no_show';

/** A unit the signed-in customer has booked before (GET /api/my-vehicles) */
export type SavedVehicle = {
    id: number;
    year: number;
    model: string;
    type: string;
    plate: string;
    /** 826 has reviewed its size, so its price is instant */
    sizeReviewed: boolean;
};

export type PaymentMethodKey = 'gcash' | 'maya';

export type PaymentMethod = {
    key: PaymentMethodKey;
    label: string;
    /** The QR the branch uploaded */
    qr: string | null;
    accountName: string | null;
    accountNumber: string | null;
};

/** A customer's booking (`toCustomer`) */
export type CustomerBooking = {
    id: number;
    /** "826-AN8W2" */
    reference: string;
    /** Site-relative, without site.base, with the private key: "/bookings/4?key=…" */
    url: string;
    kind: 'onsite' | 'home';
    status: string;
    stage: Stage;
    branch: {
        id: number;
        name: string;
        slug: string;
        address: string | null;
        phone: string | null;
        phoneHref: string | null;
        messenger: string | null;
        map: BranchMap | null;
    } | null;
    /** "2026-11-24" */
    dropOffOn: string | null;
    readyOn: string | null;
    estimate: Estimate | null;
    /** "Mon, Nov 24 · ready around Wed, Nov 26" */
    when: string;
    arrivedAt: string | null;
    exitedAt: string | null;
    preferredDate: string | null;
    preferredWindow: HomeWindow | null;
    vehicle: { id: number | null; year: number | null; model: string | null; type: string | null; typeLabel: string | null; plate: string | null; size: string | null; sizeReviewed: boolean };
    items: { name: string; category: string | null; estimateDays: number | null }[];
    /** Not in the contract; used for "Book again" when the server sends it */
    serviceIds?: number[];
    /** "reviewing" until 826 has set the unit's size; with a referral discount, total is what is left after it */
    price: { status: 'reviewing' | 'set'; total: number | null; subtotal: number | null; discount: number | null; items: { name: string; price: number | null }[] };
    address: string | null;
    area: string | null;
    /** The reservation fee */
    amountDue: number | null;
    /** What's left to pay at the branch, once the fee is in */
    balance: number | null;
    /** Only while awaiting_payment */
    holdExpiresAt: string | null;
    payment: {
        method: PaymentMethodKey | null;
        submittedAt: string | null;
        /** Set after the branch turned a screenshot down */
        rejectedReason: string | null;
        proofUrl: string | null;
        paidAt: string | null;
    };
    methods: PaymentMethod[];
    instructions: string | null;
    canCancel: boolean;
    canReschedule: boolean;
    /** Exited, the signed-in customer's own, not reviewed yet */
    canReview: boolean;
    review: MyReview | null;
    createdAt: string | null;
};

declare global {
    interface Window {
        APP826: SiteData;
    }
}

/** GET /api/my-referral */
export type MyReferral = {
    enabled: boolean;
    code: string | null;
    /** "₱500 off" or "10% off" */
    discountLabel: string | null;
    usedCount: number;
    pointsEarned: number;
    pointsPending: number;
};

/** GET /api/me: the signed-in customer's own details */
export type MyProfile = {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    avatar: string | null;
    referralCode: string | null;
    signInMethod: SignInMethod;
    emailVerified: boolean;
};

/** GET /api/my-vehicles/{id}: one unit and every booking made for it */
export type UnitHistoryLine = {
    id: number;
    reference: string;
    /** "2026-11-24" */
    date: string | null;
    branch: string | null;
    services: string[];
    status: Stage;
    price: number | null;
};

export type UnitDetail = {
    vehicle: SavedVehicle;
    history: UnitHistoryLine[];
};

/** GET /api/my-rewards */
export type MyRewards = {
    enabled: boolean;
    code: string | null;
    discountLabel: string | null;
    pointsBalance: number;
    pointsEarned: number;
    pointsSpent: number;
    pesoPerPoint: number | null;
    friends: { name: string; usedOn: string; status: string; points: number }[];
    ledger: { date: string; kind: 'earned' | 'spent' | 'adjusted'; points: number; note: string }[];
};

/** One finished unit in GET /api/my-warranty */
export type WarrantyUnit = {
    vehicleId: number | null;
    vehicleLabel: string;
    services: { name: string; finishedOn: string; endsOn: string | null; status: 'active' | 'expired' | 'none'; months: number | null; backjobTypesCovered: string[] }[];
};
