import { GOOGLE_IOS_CLIENT_ID, GOOGLE_WEB_CLIENT_ID } from './config';

/** True when this build knows a Google client id; without one the button stays hidden */
export const googleConfigured = () => GOOGLE_WEB_CLIENT_ID !== '';

/** Opens Google's account picker and returns the ID token the server verifies. null: the person backed out. */
export async function googleIdToken(): Promise<string | null> {
    // Loaded here so a build without the native module (or a test) never touches it
    const { GoogleSignin, isSuccessResponse } = require('@react-native-google-signin/google-signin') as typeof import('@react-native-google-signin/google-signin');

    GoogleSignin.configure({ webClientId: GOOGLE_WEB_CLIENT_ID, ...(GOOGLE_IOS_CLIENT_ID ? { iosClientId: GOOGLE_IOS_CLIENT_ID } : {}) });
    await GoogleSignin.hasPlayServices({ showPlayServicesUpdateDialog: true });

    const response = await GoogleSignin.signIn();

    return isSuccessResponse(response) ? response.data.idToken : null;
}
