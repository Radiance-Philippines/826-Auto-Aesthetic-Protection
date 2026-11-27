import '@ui-sdk/web/input';

// The PIN field stays out of sight for visitors. The team brings it up with
// Ctrl+0 (Cmd+0 on a Mac), or five taps on the logo on a phone.
const form = document.querySelector<HTMLFormElement>('form[data-pin]')!;

const show = () => {
    form.hidden = false;
    form.querySelector('input[name="pin"]')!.focus();
};

document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key === '0') {
        event.preventDefault(); // the browser's "reset zoom"
        show();
    }
});

let taps = 0;
let first = 0;

document.querySelector('img')!.addEventListener('click', () => {
    if (Date.now() - first > 3000) {
        first = Date.now();
        taps = 0;
    }

    if (++taps === 5) {
        show();
    }
});
