import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { StageLine } from '@/ui/StageLine';
import { Chip } from '@/ui/Chip';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

async function render(element: ReactElement): Promise<string> {
    let renderer!: ReactTestRenderer;
    await act(async () => {
        renderer = create(element);
    });

    return JSON.stringify(renderer.toJSON());
}

describe('ui', () => {
    it('StageLine names the current step for a screen reader', async () => {
        const tree = await render(<StageLine stage="in_progress" />);
        expect(tree).toContain('Progress: Working, step 2 of 4');
        for (const label of ['Arrived', 'Working', 'Check', 'Ready']) expect(tree).toContain(label);
    });

    it('Chip carries its label', async () => {
        expect(await render(<Chip label="In progress" tone="teal" />)).toContain('In progress');
    });
});
