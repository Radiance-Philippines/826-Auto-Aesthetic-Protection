import { type Client, query } from '@826/core';
import type {
    AppRelease, SystemInfo, CustomerDetail, CustomerPage, Inbox, MoveTo, Overview, Period, QuickReply, Queue, ReplyResult, ConversationRow, SignInResponse, SignedIn, Thread, Today, UnitDetail, UploadFile, StaffUser, VehicleSize,
} from './types';

/** Every endpoint the app uses, in one place. Paths are relative to `<base>/api`. */
export function createApi(client: Client) {
    return {
        // Public
        latestRelease: () => client.get<AppRelease | Record<string, never>>('/staff/latest', { auth: false }),

        // Sign in (the second step carries the one-time challenge the first returned)
        signIn: (email: string, password: string, deviceName: string) => client.post<SignInResponse>('/staff/sign-in', { email, password, device_name: deviceName }, { auth: false }),
        twoFactor: (challenge: string, code: string) => client.post<SignedIn>('/staff/sign-in/two-factor', { challenge, code }, { auth: false }),
        signOut: () => client.post<{ ok?: boolean }>('/staff/sign-out'),
        me: () => client.get<StaffUser>('/staff/me'),

        // Queue and units
        queue: (branch: number | null, date: string) => client.get<Queue>(`/staff/queue${query({ branch, date })}`),
        unit: (id: number) => client.get<UnitDetail>(`/staff/bookings/${id}`),
        setStage: (id: number, to: MoveTo) => client.post<UnitDetail>(`/staff/bookings/${id}/stage`, { to }),
        setReadyDate: (id: number, date: string) => client.post<UnitDetail>(`/staff/bookings/${id}/ready-date`, { date }),
        addNote: (id: number, body: string) => client.post<UnitDetail>(`/staff/bookings/${id}/notes`, { body }),
        addPhoto: (id: number, file: UploadFile) => {
            const form = new FormData();
            form.append('photo', file as unknown as Blob);

            return client.upload<UnitDetail>(`/staff/bookings/${id}/photos`, form);
        },

        // Sales
        today: (branch: number | null) => client.get<Today>(`/staff/today${query({ branch })}`),
        vehicleSizes: () => client.get<VehicleSize[]>('/staff/vehicle-sizes'),
        setSize: (id: number, vehicleSizeId: number) => client.post<UnitDetail>(`/staff/bookings/${id}/size`, { vehicle_size_id: vehicleSizeId }),
        approvePayment: (id: number) => client.post<UnitDetail>(`/staff/bookings/${id}/payment/approve`),
        rejectPayment: (id: number, reason: string) => client.post<UnitDetail>(`/staff/bookings/${id}/payment/reject`, { reason }),

        // Clients
        customers: (q: string, page: number) => client.get<CustomerPage>(`/staff/customers${query({ q, page })}`),
        customer: (id: number) => client.get<CustomerDetail>(`/staff/customers/${id}`),
        updateCustomer: (id: number, changes: { phone?: string; notes?: string }) => client.patch<CustomerDetail>(`/staff/customers/${id}`, changes),

        // Inbox
        inbox: (branch: string, filter: string, q: string) => client.get<Inbox>(`/staff/inbox${query({ branch, filter, q })}`),
        thread: (id: number) => client.get<Thread>(`/staff/inbox/${id}`),
        reply: (id: number, body: string) => client.post<ReplyResult>(`/staff/inbox/${id}/reply`, { body }),
        setDone: (id: number, done: boolean) => client.post<ConversationRow>(`/staff/inbox/${id}/done`, { done }),
        quickReplies: (conversation?: number) => client.get<{ replies: QuickReply[] }>(`/staff/inbox/quick-replies${query({ conversation })}`),

        // System (826 System app)
        system: () => client.get<SystemInfo>('/staff/system'),

        // Overview
        overview: (period: Period, branch: number | null) => client.get<Overview>(`/staff/overview${query({ period, branch })}`),
    };
}

export type Api = ReturnType<typeof createApi>;
