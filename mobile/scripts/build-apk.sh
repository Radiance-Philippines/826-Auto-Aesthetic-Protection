#!/usr/bin/env bash
# Local release APK build. Needs ~/Android/env.sh and ~/backups/826-app/keystore.env (never in the repo).
# Usage: EXPO_PUBLIC_API_URL=https://dran.dev/demo/826 EXPO_PUBLIC_DEMO=1 scripts/build-apk.sh
set -euo pipefail
cd "$(dirname "$0")/.."
. "$HOME/Android/env.sh"
set -a; . "$HOME/backups/826-app/keystore.env"; set +a
export EXPO_PUBLIC_API_URL="${EXPO_PUBLIC_API_URL:-https://dran.dev/demo/826}"
export EXPO_PUBLIC_DEMO="${EXPO_PUBLIC_DEMO:-1}"
export NODE_ENV=production
npx expo prebuild --platform android --clean --no-install
# Release signing from env, injected into the generated (gitignored) android/ project.
cat >> android/app/build.gradle <<'G'

android {
    signingConfigs {
        release {
            storeFile file(System.getenv("AAP826_STORE_FILE"))
            storePassword System.getenv("AAP826_STORE_PASSWORD")
            keyAlias System.getenv("AAP826_KEY_ALIAS")
            keyPassword System.getenv("AAP826_KEY_PASSWORD")
        }
    }
    buildTypes { release { signingConfig signingConfigs.release } }
}
G
(cd android && ./gradlew assembleRelease --no-daemon -PreactNativeArchitectures=arm64-v8a,x86_64 -Dorg.gradle.jvmargs=-Xmx4g)
VER=$(node -p "require('./app.json').expo.version")
mkdir -p dist
cp android/app/build/outputs/apk/release/app-release.apk "dist/aap826-$VER.apk"
ls -lh "dist/aap826-$VER.apk"; sha256sum "dist/aap826-$VER.apk"
