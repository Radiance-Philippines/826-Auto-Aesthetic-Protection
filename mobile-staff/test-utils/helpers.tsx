import type { ReactElement } from 'react';
import { act, create, type ReactTestInstance, type ReactTestRenderer } from 'react-test-renderer';
import { setMockRole, mockUser, type MockRole } from '@/api/mock';
import { AuthContext, type Auth } from '@/context/auth';
import { saveToken } from '@/lib/storage';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

export const authFor = (role: MockRole): Auth => ({ status: 'in', user: mockUser(role), accept: async () => {}, refresh: async () => {}, signOut: async () => {}, retry: async () => {} });

export const settle = () => act(async () => { await new Promise((resolve) => setTimeout(resolve, 30)); });

/** Renders a screen as one of the four roles, against the fake API, and waits for it to load */
export async function renderAs(element: ReactElement, role: MockRole = 'operations') {
    setMockRole(role);
    await saveToken('mock-token');
    let renderer!: ReactTestRenderer;
    await act(async () => {
        renderer = create(<AuthContext.Provider value={authFor(role)}>{element}</AuthContext.Provider>);
    });
    await settle();
    await settle();

    return renderer;
}

/** Everything on screen as one string (the RefreshControl points back at its own fiber, so it is left out) */
export const dump = (renderer: ReactTestRenderer): string => JSON.stringify(renderer.toJSON(), (key, value) => (key === 'refreshControl' ? undefined : value));

export const byLabel = (renderer: ReactTestRenderer, label: string): ReactTestInstance[] => renderer.root.findAll((node) => node.props.accessibilityLabel === label && typeof node.props.onPress === 'function');

export async function press(renderer: ReactTestRenderer, label: string) {
    const target = byLabel(renderer, label)[0];
    if (!target) throw new Error(`No button labelled "${label}"`);
    await act(async () => {
        target.props.onPress();
    });
    await settle();
}

export async function type(renderer: ReactTestRenderer, label: string, text: string) {
    const field = renderer.root.findAll((node) => node.props.accessibilityLabel === label && typeof node.props.onChangeText === 'function')[0];
    if (!field) throw new Error(`No field labelled "${label}"`);
    await act(async () => {
        field.props.onChangeText(text);
    });
}
