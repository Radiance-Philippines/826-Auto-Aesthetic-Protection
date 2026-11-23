/** The Laravel site the app talks to, no trailing slash (a demo copy lives in a folder) */
export const API_URL = (process.env.EXPO_PUBLIC_API_URL ?? 'https://dran.dev/demo/826').replace(/\/+$/, '');
/** Run against the built-in fake API (jest and screenshots, no server) */
export const MOCK = process.env.EXPO_PUBLIC_MOCK === '1';
