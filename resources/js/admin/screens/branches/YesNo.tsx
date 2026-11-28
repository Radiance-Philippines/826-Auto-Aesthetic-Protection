import { Icon } from '../../ui/icons';

/** Filament's boolean icon column: a mark, and the word for screen readers */
export function YesNo({ yes }: { yes: boolean }) {
    return (
        <>
            <Icon name={yes ? 'circle-check' : 'circle-x'} className={`inline size-5 ${yes ? 'text-primary' : 'text-muted-foreground'}`} />
            <span className="sr-only">{yes ? 'Yes' : 'No'}</span>
        </>
    );
}
