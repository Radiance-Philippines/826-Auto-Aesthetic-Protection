const fs = require('fs');
const path = require('path');

// google-services.json (Android push) and GoogleService-Info.plist (iOS) are the owner's Firebase files and are not in git.
// Without them the build still works, just with no push.
const androidPush = fs.existsSync(path.join(__dirname, 'google-services.json'));
const iosPush = fs.existsSync(path.join(__dirname, 'GoogleService-Info.plist'));
const dev = process.env.APP_VARIANT === 'dev';

// Google's iOS sign-in needs the reversed client id as a URL scheme. Left out until the owner gives the iOS client id.
const iosClient = (process.env.EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID ?? '').replace(/\.apps\.googleusercontent\.com$/, '');

module.exports = ({ config }) => ({
    ...config,
    extra: { ...config.extra, push: androidPush || iosPush, eas: { projectId: process.env.EAS_PROJECT_ID } },
    plugins: [
        ...(config.plugins ?? []),
        ...(iosClient ? [['@react-native-google-signin/google-signin', { iosUrlScheme: `com.googleusercontent.apps.${iosClient}` }]] : []),
        // The local dev build may talk to the laptop over http; a release never does.
        ...(dev ? [['expo-build-properties', { android: { usesCleartextTraffic: true } }]] : []),
    ],
    android: {
        ...config.android,
        ...(androidPush ? { googleServicesFile: './google-services.json' } : {}),
    },
    ios: {
        ...config.ios,
        ...(iosPush ? { googleServicesFile: './GoogleService-Info.plist' } : {}),
    },
});
