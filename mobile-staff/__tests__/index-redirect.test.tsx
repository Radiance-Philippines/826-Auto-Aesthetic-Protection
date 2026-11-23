import { act, create } from 'react-test-renderer';
import Index from '../app/index';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockState: { user: { tabs: string[] } | null } = { user: null };
const mockRedirect = jest.fn((_props: { href: string }) => null);

jest.mock('expo-router', () => ({ Redirect: (props: { href: string }) => mockRedirect(props) }));
jest.mock('@/context/auth', () => ({ useAuth: () => ({ user: mockState.user }) }));

beforeEach(() => mockRedirect.mockClear());

describe('the first screen', () => {
    it('sends a signed-out person to sign in (the tabs are protected: a redirect there shows a blank screen)', async () => {
        mockState.user = null;
        await act(async () => {
            create(<Index />);
        });
        expect(mockRedirect).toHaveBeenCalledWith({ href: '/sign-in' });
    });

    it('sends a signed-in person to the first tab the server gave them', async () => {
        mockState.user = { tabs: ['today', 'inbox', 'me'] };
        await act(async () => {
            create(<Index />);
        });
        expect(mockRedirect).toHaveBeenCalledWith({ href: '/today' });
    });
});
