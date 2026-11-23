const dev = process.env.APP_VARIANT === 'dev';

module.exports = ({ config }) => ({
    ...config,
    extra: { ...config.extra, eas: { projectId: process.env.EAS_PROJECT_ID } },
    plugins: [
        ...(config.plugins ?? []),
        // The local dev build may talk to the laptop over http; a release never does.
        ...(dev ? [['expo-build-properties', { android: { usesCleartextTraffic: true } }]] : []),
    ],
});
