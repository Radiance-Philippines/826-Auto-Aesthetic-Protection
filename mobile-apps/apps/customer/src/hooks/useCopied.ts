import * as Clipboard from 'expo-clipboard';
import { useCallback, useState } from 'react';

/** Copies text and says so for two seconds: `const [copied, copy] = useCopied()` */
export function useCopied(): [boolean, (value: string) => Promise<void>] {
    const [copied, setCopied] = useState(false);
    const copy = useCallback(async (value: string) => {
        await Clipboard.setStringAsync(value);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }, []);

    return [copied, copy];
}
