import { type Client, query } from './client';
import type {
    AppGuest,
    AppRelease,
    Catalog,
    CustomerBooking,
    Home,
    HomeRequestInput,
    MonthAvailability,
    MyReview,
    PaymentMethodKey,
    ReservationInput,
    Rewards,
    SavedVehicle,
    Showcase,
    SignInResult,
    UploadFile,
    VehicleDetail,
    WarrantyUnit,
} from './types';

/** Whose dates these are for: a new booking's branch and services, or an existing booking being moved */
export type JobQuery = { kind: 'new'; branch: number; services: number[] } | { kind: 'move'; booking: number };

const jobParams = (job: JobQuery) => (job.kind === 'new' ? { branch: job.branch, services: [...job.services].sort((a, b) => a - b) } : { booking: job.booking });

/** Every endpoint the app uses, in one place. Paths are relative to `<base>/api`. */
export function createApi(client: Client) {
    return {
        // Public
        catalog: () => client.get<Catalog>('/app/catalog', { auth: false }),
        showcase: () => client.get<Showcase>('/app/showcase', { auth: false }),
        latestRelease: () => client.get<AppRelease | Record<string, never>>('/app/latest', { auth: false }),

        // Sign in
        signInGoogle: (idToken: string, deviceName: string) => client.post<SignInResult>('/app/sign-in/google', { id_token: idToken, device_name: deviceName }, { auth: false }),
        signInApple: (identityToken: string, deviceName: string, fullName?: string | null) =>
            client.post<SignInResult>('/app/sign-in/apple', { identity_token: identityToken, device_name: deviceName, ...(fullName ? { full_name: fullName } : {}) }, { auth: false }),
        signInDemo: (deviceName: string) => client.post<SignInResult>('/app/sign-in/demo', { device_name: deviceName }, { auth: false }),
        signOut: () => client.post<{ ok?: boolean }>('/app/sign-out'),
        deleteAccount: () => client.post<{ ok?: boolean }>('/app/account/delete'),

        // Me
        me: () => client.get<AppGuest>('/app/me'),
        updateMe: (changes: { name?: string; phone?: string }) => client.patch<AppGuest>('/app/me', changes),
        registerPush: (token: string, platform: 'android' | 'ios') => client.post<{ ok?: boolean }>('/app/push-token', { token, platform }),

        // Read
        home: () => client.get<Home>('/app/home'),
        rewards: () => client.get<Rewards>('/my-rewards'),
        vehicles: () => client.get<{ vehicles: SavedVehicle[] }>('/my-vehicles'),
        vehicle: (id: number) => client.get<VehicleDetail>(`/my-vehicles/${id}`),
        warranty: () => client.get<WarrantyUnit[] | { units: WarrantyUnit[] }>('/my-warranty'),
        bookings: () => client.get<{ bookings: CustomerBooking[] }>('/my-bookings'),
        booking: (id: number) => client.get<{ booking: CustomerBooking }>(`/my-bookings/${id}`),
        availability: (job: JobQuery, month?: string) => client.get<MonthAvailability>(`/availability${query({ ...jobParams(job), month })}`),

        // Write
        reserve: (input: ReservationInput) => client.post<{ ok: boolean; booking?: CustomerBooking; demo?: boolean; readyOn?: string | null }>('/bookings', input),
        requestHome: (input: HomeRequestInput) => client.post<{ ok: boolean; booking?: CustomerBooking; demo?: boolean }>('/home-requests', input),
        cancel: (id: number, reason: string) => client.post<{ booking: CustomerBooking }>(`/my-bookings/${id}/cancel`, reason.trim() ? { reason: reason.trim() } : {}),
        reschedule: (id: number, date: string) => client.post<{ booking: CustomerBooking; demo?: boolean }>(`/my-bookings/${id}/reschedule`, { date }),
        pay: (id: number, method: PaymentMethodKey, proof: UploadFile) => {
            const form = new FormData();
            form.append('method', method);
            form.append('proof', proof as unknown as Blob);

            return client.upload<{ booking: CustomerBooking; demo?: boolean }>(`/my-bookings/${id}/payment`, form);
        },
        review: (id: number, rating: number, body: string, photo: UploadFile | null) => {
            const form = new FormData();
            form.append('rating', String(rating));
            form.append('body', body.trim());

            if (photo) {
                form.append('photo', photo as unknown as Blob);
            }

            return client.upload<{ ok: boolean; demo?: boolean; review?: MyReview }>(`/my-bookings/${id}/review`, form);
        },
    };
}

export type Api = ReturnType<typeof createApi>;
