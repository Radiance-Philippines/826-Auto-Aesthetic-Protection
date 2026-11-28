import { useState } from 'react';

/** A choice kept in this browser; storage that is off or full just means it is not remembered */
export function useStored<Value>(key: string, initial: Value) {
    const [value, setValue] = useState<Value>(() => {
        try {
            const kept = localStorage.getItem(key);

            return kept === null ? initial : (JSON.parse(kept) as Value);
        } catch {
            return initial;
        }
    });

    const keep = (next: Value) => {
        setValue(next);

        try {
            localStorage.setItem(key, JSON.stringify(next));
        } catch {
            // not remembered
        }
    };

    return [value, keep] as const;
}
