import '@ui-sdk/web/menu';

// Picking a branch sends the form, which reloads the page in that branch.
// Capture: ui-sdk's valuechange does not bubble.
document.addEventListener('valuechange', (event) => {
    const selector = (event.target as Element).closest?.('[data-branch-selector]');
    const form = selector?.querySelector('form');

    if (form) {
        const value = (event as CustomEvent<{ value: string }>).detail.value;

        form.querySelector<HTMLInputElement>('input[name="branch"]')!.value = value === 'all' ? '' : value;
        form.requestSubmit();
    }
}, true);
