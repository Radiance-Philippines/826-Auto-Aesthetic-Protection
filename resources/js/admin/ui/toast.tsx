import { createToaster, Toast, ToastClose, ToastDescription, ToastRegion, ToastTitle } from '@ui-sdk/react/toast';

import { Icon } from './icons';

/** `toast.success('Saved')`, `toast.error(message)`: announced to screen readers. <Toaster /> shows them, once. */
export const toast = createToaster();

export function Toaster() {
    return (
        <ToastRegion toaster={toast} side="bottom" align="end">
            {(data) => (
                <Toast className="flex items-start gap-3 rounded-md border border-border bg-card p-3 pe-2 text-sm text-foreground shadow-lg">
                    <Icon name={data.type === 'error' ? 'circle-alert' : 'circle-check'} className={`mt-0.5 size-4 ${data.type === 'error' ? 'text-destructive' : 'text-primary'}`} />
                    <div className="min-w-0 flex-1">
                        <ToastTitle className="font-medium break-words" />
                        <ToastDescription className="text-muted-foreground" />
                    </div>
                    <ToastClose aria-label="Dismiss" className="flex size-6 cursor-pointer items-center justify-center rounded-sm border-0 bg-transparent p-0 text-muted-foreground">
                        <Icon name="x" />
                    </ToastClose>
                </Toast>
            )}
        </ToastRegion>
    );
}
