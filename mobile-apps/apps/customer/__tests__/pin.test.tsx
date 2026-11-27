import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { Button, Field, ThemeProvider } from '@826/core';
import { Text } from 'react-native';

import { api } from '@/api';
import { pinnedFetch } from '@/lib/pin';
import { PinGate } from '@/ui/PinGate';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockSaved: string[] = [];

jest.mock('@/lib/config', () => ({ API_URL: 'https://dran.dev/demo/826', DEMO: true, MOCK: true }));
jest.mock('@/lib/storage', () => ({ getToken: async () => null, getPin: async () => null, savePin: async (code: string) => void mockSaved.push(code) }));

const answer = (status: number) => ({ status }) as Response;

it('shows the PIN screen on a 423, sends the PIN afterwards, and opens when the server takes it', async () => {
    const server = jest.fn(async (_input: unknown, init?: RequestInit) => answer((init?.headers as Record<string, string> | undefined)?.['X-Site-Pin'] === '314159' ? 200 : 423));
    globalThis.fetch = server as unknown as typeof fetch;
    jest.spyOn(api, 'catalog')
        .mockResolvedValueOnce({ kind: 'failed', message: 'That PIN is not right.', code: 'pin_required' })
        .mockResolvedValue({ kind: 'ok', data: {} as never });

    let r!: ReactTestRenderer;
    await act(async () => {
        r = create(
            <ThemeProvider scheme="dark">
                <PinGate>
                    <Text>the app</Text>
                </PinGate>
            </ThemeProvider>,
        );
    });
    expect(JSON.stringify(r.toJSON())).toContain('the app');

    await act(async () => void (await pinnedFetch('https://x/api/app/me')));
    expect(JSON.stringify(r.toJSON())).toContain('Team testing');

    const field = () => r.root.findByType(Field);
    const open = () => act(async () => r.root.findByType(Button).props.onPress());

    await act(async () => field().props.onChangeText('000000'));
    await open();
    expect(field().props.error).toBe('That PIN is not right.');

    await act(async () => field().props.onChangeText('314159'));
    await open();
    expect(JSON.stringify(r.toJSON())).toContain('the app');
    expect(mockSaved.at(-1)).toBe('314159');

    await act(async () => void (await pinnedFetch('https://x/api/app/me', { headers: { Accept: 'application/json' } })));
    expect(server.mock.calls.at(-1)?.[1]?.headers).toEqual({ Accept: 'application/json', 'X-Site-Pin': '314159' });
});
