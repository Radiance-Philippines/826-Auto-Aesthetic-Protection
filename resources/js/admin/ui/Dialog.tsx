import { AlertDialog, AlertDialogBackdrop, AlertDialogClose, AlertDialogContent, AlertDialogDescription, AlertDialogTitle, createConfirm } from '@ui-sdk/react/alert-dialog';
import { Dialog, DialogBackdrop, DialogClose, DialogContent, DialogDescription, DialogTitle } from '@ui-sdk/react/dialog';
import type { ReactNode } from 'react';

import { buttonClass } from './Button';
import { Icon, type IconName } from './icons';

const panelClass = 'max-h-[calc(100dvh-2rem)] w-[min(28rem,calc(100vw-2rem))] overflow-y-auto rounded-xl border border-border bg-card p-5 text-foreground shadow-lg';

/** A focused task over the page (a sheet on small touch screens). Escape and the close button leave it. */
export function Modal({ open, onClose, title, description, children }: { open: boolean; onClose: () => void; title: string; description?: ReactNode; children: ReactNode }) {
    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()} presentation="auto">
            <DialogBackdrop />
            <DialogContent className={panelClass}>
                <div className="mb-4 pe-10">
                    <DialogTitle className="text-base font-semibold">{title}</DialogTitle>
                    {description && <DialogDescription className="mt-1 text-sm text-muted-foreground">{description}</DialogDescription>}
                </div>
                {children}
                <DialogClose aria-label="Close" className={`${buttonClass('ghost', 'sm', true)} absolute end-3 top-3 shadow-none`}>
                    <Icon name="x" />
                </DialogClose>
            </DialogContent>
        </Dialog>
    );
}

/** The foot of a dialog's form: the way out first, the action last */
export function ModalActions({ children, onCancel }: { children: ReactNode; onCancel: () => void }) {
    return (
        <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" className={buttonClass()} onClick={onCancel}>
                <Icon name="x" />
                Cancel
            </button>
            {children}
        </div>
    );
}

type Question = { title: string; description: string; label: string; icon: IconName; destructive?: boolean };

const ask = createConfirm<Question>();

/** `if (await confirm({...}))`: for a step that cannot be undone from here. Needs <ConfirmHost /> once. */
export const confirm = (question: Question) => ask(question);

export function ConfirmHost() {
    return (
        <AlertDialog controller={ask.controller}>
            <AlertDialogBackdrop />
            <AlertDialogContent<Question> className={panelClass}>
                {(state) =>
                    state.payload && (
                        <>
                            <AlertDialogTitle className="text-base font-semibold">{state.payload.title}</AlertDialogTitle>
                            <AlertDialogDescription className="mt-1 text-sm text-muted-foreground">{state.payload.description}</AlertDialogDescription>
                            <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                <AlertDialogClose className={buttonClass()}>
                                    <Icon name="x" />
                                    Cancel
                                </AlertDialogClose>
                                <AlertDialogClose value="confirm" className={buttonClass(state.payload.destructive ? 'destructive' : 'primary')}>
                                    <Icon name={state.payload.icon} />
                                    {state.payload.label}
                                </AlertDialogClose>
                            </div>
                        </>
                    )
                }
            </AlertDialogContent>
        </AlertDialog>
    );
}
