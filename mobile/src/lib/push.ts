import Constants from 'expo-constants';
import { Platform } from 'react-native';

/**
 * Push plumbing, dormant: nothing is asked or sent until the owner adds
 * Firebase (Android google-services.json) or an Apple push key and the
 * server has credentials (app.config.js sets extra.push when the files
 * exist). The notification permission prompt is therefore never shown now.
 */
export const pushReady = () => Constants.expoConfig?.extra?.push === true;

/** The device token to send to POST /api/app/push-token, or null while push is not set up */
export async function devicePushToken(): Promise<{ token: string; platform: 'android' | 'ios' } | null> {
    if (!pushReady()) {
        return null;
    }

    const Notifications = require('expo-notifications') as typeof import('expo-notifications');
    const { status } = await Notifications.requestPermissionsAsync();

    if (status !== 'granted') {
        return null;
    }

    // APNs on iOS, FCM on Android: the server sends to the raw device token
    const token = await Notifications.getDevicePushTokenAsync();

    return { token: String(token.data), platform: Platform.OS === 'ios' ? 'ios' : 'android' };
}
