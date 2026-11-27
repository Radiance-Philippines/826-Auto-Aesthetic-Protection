/* A list people put in order (Filament's drag to reorder), shared by the shop and site screens */
import { useState } from 'react';

import { api, errorMessage, fieldErrors } from '../../lib/api';
import type { MenuAction } from '../../ui/Menu';
import { toast } from '../../ui/toast';

/** `path` answers POST {path}/reorder with the whole list's ids */
export function useReorder<Row extends { id: number }>(loaded: Row[], path: string, reload: () => void, name: (row: Row) => string) {
    /** The order just chosen, shown while the server saves it */
    const [chosen, setChosen] = useState<number[] | null>(null);
    const rows = chosen ? chosen.flatMap((id) => loaded.find((row) => row.id === id) ?? []) : loaded;

    const refresh = () => {
        setChosen(null);
        reload();
    };

    const save = async (ids: number[]): Promise<boolean> => {
        setChosen(ids);

        try {
            await api('POST', `${path}/reorder`, { ids });

            return true;
        } catch (failure) {
            toast.error(fieldErrors(failure).ids ?? errorMessage(failure));
            refresh();

            return false;
        }
    };

    /** The table gives the new order of the rows on screen */
    const reorderPage = (ids: string[], page: number, pageSize: number) => {
        const all = rows.map((row) => row.id);
        all.splice((page - 1) * pageSize, ids.length, ...ids.map(Number));
        void save(all);
    };

    const move = async (row: Row, by: -1 | 1) => {
        const all = rows.map((each) => each.id);
        const from = all.indexOf(row.id);
        [all[from], all[from + by]] = [all[from + by], all[from]];

        if (await save(all)) {
            toast.success(`${name(row)} moved to position ${from + by + 1} of ${all.length}`);
        }
    };

    /** Move up and down without dragging. `movable` is false while the list is searched, sorted or filtered. */
    const moves = (row: Row, movable = true): MenuAction[] => {
        const at = rows.findIndex((each) => each.id === row.id);

        return [
            { id: 'up', label: 'Move up', icon: 'arrow-up', disabled: !movable || at === 0, onSelect: () => void move(row, -1) },
            { id: 'down', label: 'Move down', icon: 'arrow-down', disabled: !movable || at === rows.length - 1, onSelect: () => void move(row, 1) },
        ];
    };

    return { rows, refresh, reorderPage, moves };
}
