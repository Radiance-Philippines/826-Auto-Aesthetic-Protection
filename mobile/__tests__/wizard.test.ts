import { emptyDraft, emptyHomeDraft, homeInput, phoneOk, reservationInput, reviewProblems, validateHome, validateStep, validateUnit } from '@/lib/wizard';
import { referralMessage } from '@/lib/share';
import { shrunkSize } from '@/lib/photo';
import { peso } from '@/lib/money';

describe('booking wizard rules (same as the site and server)', () => {
    it('checks each step', () => {
        const d = emptyDraft();
        expect(validateStep('branch', d).branch).toBeDefined();
        expect(validateStep('services', d).services).toBeDefined();
        expect(validateStep('date', d).date).toBeDefined();
        expect(validateStep('branch', { ...d, branchId: 1 })).toEqual({});
    });

    it('needs a full new unit, or a saved one', () => {
        expect(Object.keys(validateUnit({ mode: 'new', vehicleId: null, year: '', model: '', type: '', plate: '' }, 2027))).toEqual(['year', 'model', 'type', 'plate']);
        expect(validateUnit({ mode: 'new', vehicleId: null, year: '2023', model: 'BMW 7 Series', type: 'sedan', plate: 'NDA 4821' }, 2027)).toEqual({});
        expect(validateUnit({ mode: 'new', vehicleId: null, year: '1900', model: 'X', type: 'sedan', plate: 'AB!' }, 2027)).toMatchObject({ year: expect.any(String), plate: 'Use letters and numbers only for the plate.' });
        expect(validateUnit({ mode: 'saved', vehicleId: null, year: '', model: '', type: '', plate: '' }).vehicle).toBeDefined();
        expect(validateUnit({ mode: 'saved', vehicleId: 4, year: '', model: '', type: '', plate: '' })).toEqual({});
    });

    it('takes 10 to 13 digit phone numbers', () => {
        expect(phoneOk('0917 123 4567')).toBe(true);
        expect(phoneOk('+63 917 123 4567')).toBe(true);
        expect(phoneOk('12345')).toBe(false);
        expect(phoneOk('abc')).toBe(false);
    });

    it('builds the reservation the server expects', () => {
        const body = reservationInput({ ...emptyDraft(), branchId: 2, serviceIds: [31, 11], date: '2026-11-25', unit: { mode: 'saved', vehicleId: 7, year: '', model: '', type: '', plate: '' }, name: ' Miguel ', phone: '0917 123 4567', consent: true, referral: 'ANDREA-826' });
        expect(body).toEqual({ branch: 2, services: [11, 31], date: '2026-11-25', vehicle_id: 7, name: 'Miguel', phone: '0917 123 4567', referral_code: 'ANDREA-826', consent: true });
        const typed = reservationInput({ ...emptyDraft(), branchId: 1, serviceIds: [11], date: '2026-11-25', unit: { mode: 'new', vehicleId: null, year: '2023', model: ' BMW 7 Series', type: 'sedan', plate: 'NDA 4821' }, name: 'A', phone: '0917 123 4567', email: 'a@b.co', consent: true });
        expect(typed).toMatchObject({ vehicle_year: 2023, vehicle_model: 'BMW 7 Series', vehicle_type: 'sedan', plate_number: 'NDA 4821', email: 'a@b.co' });
        expect(typed).not.toHaveProperty('vehicle_id');
    });

    it('checks and builds a home service request', () => {
        const branch = { homeService: { offered: true, areas: ['Cainta'] } } as never;
        expect(Object.keys(validateHome(emptyHomeDraft(), branch))).toEqual(expect.arrayContaining(['branch', 'services', 'date', 'window', 'address', 'area', 'name', 'phone', 'consent']));
        const draft = { ...emptyHomeDraft(), branchId: 1, serviceIds: [11], date: '2026-11-25', window: 'morning' as const, address: '1 Main St', area: 'Other', areaOther: 'Antipolo', water: true, shade: false, unit: { mode: 'saved' as const, vehicleId: 3, year: '', model: '', type: '', plate: '' }, name: 'A', phone: '0917 123 4567', consent: true };
        expect(validateHome(draft, branch)).toEqual({});
        expect(homeInput(draft)).toMatchObject({ preferred_date: '2026-11-25', preferred_window: 'morning', area: 'Other', area_other: 'Antipolo', has_water: true, has_shade: false, vehicle_id: 3 });
        expect(homeInput(draft)).not.toHaveProperty('has_power');
    });

    it('checks a review', () => {
        expect(Object.keys(reviewProblems(0, 'short'))).toEqual(['rating', 'body']);
        expect(reviewProblems(4, 'The paint is cleaner than before.')).toEqual({});
    });
});

describe('small helpers', () => {
    it('words the referral share with the code and the site link', () => {
        expect(referralMessage('MIGUEL-826', '₱500 off', 'https://dran.dev/demo/826')).toContain('MIGUEL-826');
        expect(referralMessage('MIGUEL-826', '₱500 off', 'https://dran.dev/demo/826')).toContain('\nhttps://dran.dev/demo/826/r/MIGUEL-826');
        expect(referralMessage('MIGUEL-826', null)).not.toContain('null');
    });

    it('shrinks only big pictures', () => {
        expect(shrunkSize(4000, 3000)).toEqual({ width: 2000, height: 1500 });
        expect(shrunkSize(1200, 800)).toBeNull();
    });

    it('formats pesos', () => {
        expect(peso(1284500)).toBe('₱1,284,500');
        expect(peso(500)).toBe('₱500');
    });
});
