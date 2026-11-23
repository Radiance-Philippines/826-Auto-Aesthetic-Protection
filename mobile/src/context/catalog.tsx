import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { api, type ApiResult, type Catalog } from '@/api';

type CatalogState = { catalog: Catalog | null; error: ApiResult<unknown> | null; reload: () => Promise<void> };

const CatalogContext = createContext<CatalogState | null>(null);

/** Branches, services, unit types, booking rules and which sign-ins are on. Public, so it loads before sign-in too. */
export function CatalogProvider({ children }: { children: ReactNode }) {
    const [catalog, setCatalog] = useState<Catalog | null>(null);
    const [error, setError] = useState<ApiResult<unknown> | null>(null);

    const reload = useCallback(async () => {
        const result = await api.catalog();

        if (result.kind === 'ok') {
            setCatalog(result.data);
            setError(null);
        } else {
            setError(result);
        }
    }, []);

    useEffect(() => {
        void reload();
    }, [reload]);

    const value = useMemo(() => ({ catalog, error, reload }), [catalog, error, reload]);

    return <CatalogContext.Provider value={value}>{children}</CatalogContext.Provider>;
}

export function useCatalog(): CatalogState {
    const state = useContext(CatalogContext);

    if (!state) {
        throw new Error('useCatalog needs a CatalogProvider');
    }

    return state;
}
