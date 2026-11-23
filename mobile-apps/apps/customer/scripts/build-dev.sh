#!/usr/bin/env bash
# Local debug APK (cleartext http allowed) for the emulator or the laptop's API. No keystore needed.
# Usage: EXPO_PUBLIC_API_URL=http://10.0.2.2:8050 [EXPO_PUBLIC_MOCK=1] scripts/build-dev.sh
set -euo pipefail
cd "$(dirname "$0")/.."
. "$HOME/Android/env.sh"
export EXPO_PUBLIC_API_URL="${EXPO_PUBLIC_API_URL:-http://10.0.2.2:8050}"
export EXPO_PUBLIC_DEMO="${EXPO_PUBLIC_DEMO:-1}"
export APP_VARIANT=dev
npx expo prebuild --platform android --clean --no-install
(cd android && ./gradlew assembleDebug --no-daemon --max-workers=4 -PreactNativeArchitectures=x86_64 -Dorg.gradle.jvmargs=-Xmx4g)
VER=$(node -p "require('./app.json').expo.version")
mkdir -p dist
cp android/app/build/outputs/apk/debug/app-debug.apk "dist/aap826-dev-$VER.apk"
ls -lh "dist/aap826-dev-$VER.apk"
