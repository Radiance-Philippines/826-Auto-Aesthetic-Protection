import { createChange, createReorder, definePrimitive, type ReorderDraft } from '@ui-sdk/core';
import { createParts } from '@ui-sdk/react/primitive';

type Options = {
    value?: string[];
    onValueChange?: (value: string[]) => void;
    /** What screen readers call each item */
    labels?: Record<string, string>;
};

/**
 * Table rows in an order people set: UI SDK's reorder behaviour. Drag the
 * handle, or focus it, press Space, move with the arrows, Space to drop,
 * Escape to cancel; each step is read out.
 */
const sortableRows = definePrimitive({
    name: 'sortable-rows',
    options: { value: 'value', onValueChange: 'callback', labels: 'value' },
    controlled: ['value'],
    state: (options: Options) => ({ value: options.value ?? [], moving: null as ReorderDraft | null }),
    sync: (options) => (options.value === undefined ? {} : { value: options.value }),
    api: (context) => {
        const change = createChange(context);
        const reorder = createReorder(context, {
            slot: 'row',
            key: 'value',
            draft: 'moving',
            set: (value, details) => change('value', value, details),
            label: (key) => context.options().labels?.[key],
        });

        return { reorder };
    },
    slots: (slot) => ({
        root: slot<{}, { order: string[] }>({
            state: (state, part) => ({ order: part.api.reorder.order(state) }),
        }),
        row: slot<{ value: string }>({
            props: { value: 'string' },
            attrs: (state, part) => ({ 'data-grabbed': part.api.reorder.moving(state, part.key!) ? '' : undefined }),
            bind: (el, part) => part.api.reorder.bind(el, { key: part.key!, props: part.props }),
        }),
        handle: slot<{ value: string }>({
            props: { value: 'string' },
            attrs: (state, part) => ({ type: 'button', 'aria-pressed': String(part.api.reorder.moving(state, part.key!)) }),
            press: { none: 'The handle moves with Space and the arrows, handled on keydown.' },
            on: {
                keydown: (event, { api, key }) => {
                    api.reorder.keydown(event as KeyboardEvent, key!);
                },
                pointerdown: (event, { api, key }) => {
                    api.reorder.pointerdown(event as PointerEvent, key!);
                },
            },
        }),
    }),
});

export const { Root: SortableBody, Row: SortableRow, Handle: SortableHandle } = createParts(sortableRows, { root: 'tbody', row: 'tr', handle: 'button' });
