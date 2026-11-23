import { referralMessage, referralUrl } from '@/lib/share';

describe('referral link', () => {
    it('is the site origin plus /r/<code>, keeping a demo folder', () => {
        expect(referralUrl('MIGUEL-826', 'https://dran.dev/demo/826')).toBe('https://dran.dev/demo/826/r/MIGUEL-826');
        expect(referralUrl('MIGUEL-826', 'https://826auto.example')).toBe('https://826auto.example/r/MIGUEL-826');
    });

    it('drops a trailing slash or /api from the base', () => {
        expect(referralUrl('A-1', 'https://dran.dev/demo/826/')).toBe('https://dran.dev/demo/826/r/A-1');
        expect(referralUrl('A-1', 'https://dran.dev/demo/826/api')).toBe('https://dran.dev/demo/826/r/A-1');
    });

    it('escapes the code', () => {
        expect(referralUrl('A B/1', 'https://x.test')).toBe('https://x.test/r/A%20B%2F1');
    });

    it('uses EXPO_PUBLIC_API_URL by default and ships with the plain text', () => {
        const message = referralMessage('MIGUEL-826', '₱500 off');
        expect(message).toContain('my code MIGUEL-826 and get ₱500 off');
        expect(message.split('\n')[1]).toBe(referralUrl('MIGUEL-826'));
        expect(message.split('\n')[1]).toMatch(/\/r\/MIGUEL-826$/);
    });
});
