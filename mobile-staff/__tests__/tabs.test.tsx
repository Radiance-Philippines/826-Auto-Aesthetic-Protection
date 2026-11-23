import { create, act } from 'react-test-renderer';
import TabsLayout from '../app/(tabs)/_layout';
import { authFor } from '../test-utils/helpers';
import { AuthContext } from '@/context/auth';

jest.mock('expo-router', () => {
    const Tabs = ({ children }: { children: unknown }) => children;
    Tabs.Screen = (props: { name: string; options: { href?: null; title: string } }) => require('react').createElement('Screen', { name: props.name, hidden: props.options.href === null, title: props.options.title });

    return { Tabs };
});

async function tabsFor(role: 'operations' | 'sales_admin' | 'branch_manager' | 'super_admin') {
    let renderer!: ReturnType<typeof create>;
    await act(async () => {
        renderer = create(
            <AuthContext.Provider value={authFor(role)}>
                <TabsLayout />
            </AuthContext.Provider>,
        );
    });

    return (renderer.root.findAllByType('Screen' as never) as unknown as { props: { name: string; hidden: boolean; title: string } }[]).filter((s) => !s.props.hidden).map((s) => s.props.title);
}

describe('role -> tab bar', () => {
    it('detailer: Queue and Me', async () => expect(await tabsFor('operations')).toEqual(['Queue', 'Me']));
    it('sales: Today, Clients, Inbox, Me', async () => expect(await tabsFor('sales_admin')).toEqual(['Today', 'Clients', 'Inbox', 'Me']));
    it('manager: Queue, Today, Clients, Inbox, Me', async () => expect(await tabsFor('branch_manager')).toEqual(['Queue', 'Today', 'Clients', 'Inbox', 'Me']));
    it('owner: Queue, Today, Inbox, Overview, Me', async () => expect(await tabsFor('super_admin')).toEqual(['Queue', 'Today', 'Inbox', 'Overview', 'Me']));
});
