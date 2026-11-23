import { Platform } from 'react-native';

/** The Laravel site the app talks to, no trailing slash (a demo copy lives in a folder) */
export const API_URL = (process.env.EXPO_PUBLIC_API_URL ?? 'https://dran.dev/demo/826').replace(/\/+$/, '');
/** A demo build: shows "Try the demo account" and says nothing here is saved */
export const DEMO = process.env.EXPO_PUBLIC_DEMO === '1';
/** Run against the built-in fake API (screenshots and jest, no server) */
export const MOCK = process.env.EXPO_PUBLIC_MOCK === '1';

export const GOOGLE_WEB_CLIENT_ID = process.env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID ?? '';
export const GOOGLE_IOS_CLIENT_ID = process.env.EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID ?? '';

export const IS_IOS = Platform.OS === 'ios';
