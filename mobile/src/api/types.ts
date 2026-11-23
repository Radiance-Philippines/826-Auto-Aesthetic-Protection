/*
 * Response shapes of the 826 API. The site's own types
 * (resources/js/types.ts) are reused for what already exists; the /api/app/*
 * shapes follow the phase 2 contract. Prices are never invented: they come
 * from the server, and are null until 826 has reviewed the unit.
 */
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

export type Estimate = { days: number; label: string };
export type HomeWindow = 'morning' | 'afternoon' | 'any';
export type PaymentMethodKey = 'gcash' | 'maya';
export type DayState = 'open' | 'full' | 'closed';

export type AppGuest = {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    avatar: string | null;
    referralCode: string | null;
    source?: string | null;
};

export type SignInResult = { token: string; guest: AppGuest };

export type Service = {
    id: number;
    name: string;
    slug: string;
    summary: string | null;
    homeServiceOk: boolean;
    /** Days at the shop; null until the owner fills it in. There is no price. */
    estimateDays: number | null;
};

export type Category = { id: number; name: string; slug: string; services: Service[] };

export type BranchHours = { day: number; label: string; open: string | null; close: string | null };

export type Branch = {
    id: number;
    slug: string;
    name: string;
    status: 'open' | 'coming_soon';
    city: string | null;
    address: string | null;
    phone: string | null;
    phoneHref: string | null;
    hours: BranchHours[];
    lastDay: string;
    bookable: boolean;
    serviceIds: number[];
    homeService: { offered: boolean; areas: string[] };
};

export type VehicleType = { key: string; label: string };

export type BookingRules = {
    cancelCutoffHours: number;
    rescheduleEnabled: boolean;
    rescheduleCutoffHours: number;
    maxReschedules: number;
    policy: string | null;
    fee: { enabled: boolean; label: string | null; holdMinutes: number };
    windows: Record<HomeWindow, string>;
    referral: { enabled: boolean; discountLabel: string | null };
};

/** GET /api/app/catalog */
export type Catalog = {
    branches: Branch[];
    categories: Category[];
    vehicleTypes: VehicleType[];
    booking: BookingRules;
    signIn: { google: boolean; apple: boolean; demo: boolean };
};

export type SavedVehicle = {
    id: number;
    year: number;
    model: string;
    type: string;
    plate: string;
    sizeReviewed: boolean;
    /** The unit's latest finished work, when the server sends it (garage cards) */
    lastService?: { name: string; on: string } | null;
};

export type PaymentMethod = { key: PaymentMethodKey; label: string; qr: string | null; accountName: string | null; accountNumber: string | null };

export type MyReview = { rating: number; body: string; status: 'pending' | 'approved' | 'rejected'; photo: string | null };

/** A customer's booking (Booking::toCustomer) */
export type CustomerBooking = {
    id: number;
    reference: string;
    url: string;
    kind: 'onsite' | 'home';
    status: string;
    stage: Stage;
    branch: { id: number; name: string; slug: string; address: string | null; phone: string | null; phoneHref: string | null; messenger: string | null } | null;
    dropOffOn: string | null;
    readyOn: string | null;
    estimate: Estimate | null;
    when: string;
    arrivedAt: string | null;
    exitedAt: string | null;
    preferredDate: string | null;
    preferredWindow: HomeWindow | null;
    vehicle: { id: number | null; year: number | null; model: string | null; type: string | null; typeLabel: string | null; plate: string | null; size: string | null; sizeReviewed: boolean };
    items: { name: string; category: string | null; estimateDays: number | null }[];
    price: { status: 'reviewing' | 'set'; total: number | null; subtotal: number | null; discount: number | null; items: { name: string; price: number | null }[] };
    address: string | null;
    area: string | null;
    amountDue: number | null;
    balance: number | null;
    holdExpiresAt: string | null;
    payment: { method: PaymentMethodKey | null; submittedAt: string | null; rejectedReason: string | null; proofUrl: string | null; paidAt: string | null };
    methods: PaymentMethod[];
    instructions: string | null;
    canCancel: boolean;
    canReschedule: boolean;
    canReview: boolean;
    review: MyReview | null;
    createdAt: string | null;
};

/** GET /api/app/home */
export type Home = {
    guest: AppGuest;
    inShop: CustomerBooking | null;
    nextBooking: CustomerBooking | null;
    referral: { enabled: boolean; code: string | null; discountLabel: string | null };
    garage: SavedVehicle[];
    unread: number;
};

/** GET /api/app/showcase: the admin's Our work content, as the site shows it. Image urls are absolute. */
export type ShowcaseImage = { src: string; srcset: string | null; thumb: string };
export type ShowcaseVideo = { id: number; title: string; caption: string | null; youtubeId: string; thumbnail: string; url: string };
export type ShowcasePhoto = { id: number; alt: string; category: string; categoryLabel: string | null; image: ShowcaseImage };
export type ShowcaseReview = { id: number; name: string; rating: number; body: string; photo: ShowcaseImage | null; service: string | null; branch: string | null; date: string | null };
export type Showcase = { videos: ShowcaseVideo[]; photos: ShowcasePhoto[]; reviews: ShowcaseReview[] };

export type Rewards = {
    enabled: boolean;
    code: string | null;
    discountLabel: string | null;
    pointsBalance: number;
    pointsEarned: number;
    pointsSpent: number;
    pesoPerPoint: number | null;
    friends: { name: string; usedOn: string; status: 'pending' | 'awarded' | 'voided'; points: number }[];
    ledger: { date: string; kind: 'earned' | 'spent' | 'adjusted'; points: number; note: string | null }[];
};

export type VehicleDetail = {
    vehicle: SavedVehicle;
    history: { reference: string; date: string; branch: string | null; services: string[]; status: string; price: number | null; id: number }[];
};

export type WarrantyService = {
    name: string;
    finishedOn: string;
    endsOn: string | null;
    status: 'active' | 'expired' | 'none';
    months: number | null;
    backjobTypesCovered: string[];
};

export type WarrantyUnit = { vehicleId: number | null; vehicleLabel: string; services: WarrantyService[] };

export type AppRelease = { version: string; versionCode: number; url: string; sha256: string; notes: string | null; mandatory: boolean };

export type MonthAvailability = { month: string; first: string; last: string; days: Record<string, DayState>; estimate: Estimate | null };

/** Request bodies (the same ones the site sends) */
export type UnitInput = { vehicle_id?: number; vehicle_year?: number; vehicle_model?: string; vehicle_type?: string; plate_number?: string };
export type ContactInput = { name: string; phone: string; email?: string; message?: string; consent: boolean };
export type ReservationInput = UnitInput & ContactInput & { branch: number; services: number[]; date: string; referral_code?: string };
export type HomeRequestInput = UnitInput &
    ContactInput & {
        branch: number;
        services: number[];
        preferred_date: string;
        preferred_window: HomeWindow;
        address: string;
        area?: string;
        area_other?: string;
        landmark?: string;
        has_water?: boolean;
        has_power?: boolean;
        has_shade?: boolean;
    };

/** A picked picture, ready to upload (the RN FormData file shape) */
export type UploadFile = { uri: string; name: string; type: string };
