# 826 customer app

Expo SDK 58, React Native, TypeScript, expo-router. Android now, iOS prepared (not built yet). Customers sign in, book a drop-off day, follow their unit in the shop, see warranty and referral rewards. The Laravel API is in the repo root (`routes/api.php`, `routes/web.php`).

## Run
```
cd mobile-apps
npm install            # one install for every app
cd apps/customer
npx expo start
```
Run the API from the repo root: `php artisan serve --host=0.0.0.0 --port=8050`. The Android emulator reaches it at `http://10.0.2.2:8050`.

| Variable | Default | Meaning |
|---|---|---|
| `EXPO_PUBLIC_API_URL` | `https://dran.dev/demo/826` | Laravel site, no trailing slash |
| `EXPO_PUBLIC_DEMO` | unset | `1` shows "Try the demo account" (the server also has to allow it) |
| `EXPO_PUBLIC_MOCK` | unset | `1` uses the built-in fake API (`src/api/mock.ts`): no server needed |
| `EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID` | unset | Google web client id. No id, no Google button |
| `EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID` | unset | Google iOS client id (adds its URL scheme at prebuild) |
| `EXPO_PUBLIC_INTRO` | unset | `1` plays the opening animation. Paused by the owner: off in every release |
| `APP_VARIANT` | unset | `dev` allows cleartext http (local API). Never for release |

## Layout
- `app/` routes: `(tabs)` Home, Book, Garage, Rewards, Me; `booking/[id]`, `vehicle/[id]`, `review/[id]`, `bookings`, `warranty`, `home-service`, `sign-in`, `register`, `forgot-password` (signed out).
- `src/api` every endpoint (`endpoints.ts`), shapes (`types.ts`), the fake API (`mock.ts`). The typed client, the theme, the design-system parts (`Button`, `Screen`, `Text`, `Field`, `Sheet`, motion ...), dates, money and the token store are in `@826/core` (`../../packages/core`), shared with the team apps.
- `src/lib` pure logic: stage mapping, dates (Manila time), wizard rules, photo shrink, token storage.
- `src/ui` the customer-only parts. The dark and light palettes copied from `resources/css/app.css` are `packages/core/src/theme/theme.ts`; read them with `useTheme()` (follows the phone's light/dark setting). `packages/core/src/ui/motion.tsx` has the animation helpers (press scale, list entrance, count-up); they all stop when the phone is set to remove animations.
- Every `Button` has a leading icon (the `icon` prop is required; a jest test checks it). Customers sign in or create an account with email and password (`/api/app/auth/*`, `src/features/Account.tsx`) or with Google / Apple; the verify and reset links open in the browser (no deep link yet). Referral sharing sends `<site>/r/<code>` (the site is `EXPO_PUBLIC_API_URL`).
- The app never invents facts: prices, warranty and reward values come from the API. A price shows only after 826 reviews the unit.

## Checks
```
npx tsc --noEmit && npx jest && npx expo-doctor && npx expo export --platform android && npx expo export --platform ios
```

## Build for Android
```
scripts/build-dev.sh   # debug APK for the emulator (cleartext allowed)
EXPO_PUBLIC_API_URL=https://dran.dev/demo/826 EXPO_PUBLIC_DEMO=1 scripts/build-apk.sh   # signed release APK -> dist/aap826-<version>.apk
```
Toolchain: `source ~/Android/env.sh`. Release signing reads `~/backups/826-app/keystore.env` (outside the repo, never committed). Push stays dormant until `google-services.json` is in this folder.

## Build for iOS
The iOS app is configured and bundles (`npx expo export --platform ios` works) but it has NOT been built: iOS needs macOS or EAS cloud builds and an Apple Developer account, and none exists yet.

What the owner provides:
1. An Apple Developer Program account (paid, yearly).
2. An App Store Connect app record with bundle id `ph.radianceph.aap826`. Put its numeric id in `eas.json` (`submit.production.ios.ascAppId`).
3. A Google iOS OAuth client id (Google Cloud console, iOS type, same bundle id) for `EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID`, and a Google web client id for `EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID`. Both are also saved in the site's Settings, Google sign-in.
4. Sign in with Apple: enable the capability on the App ID, and save the bundle id as the site's Apple client id (Settings). The app already shows "Sign in with Apple" / "Create account with Apple" on iOS (App Store rule 4.8 while Google is offered).
5. For push (later): an APNs key from the Apple account added in EAS, and the site's push credentials.
6. A privacy policy URL for the App Store listing (the site has no privacy page yet).

Then, from `mobile-apps/apps/customer/`:
```
npm i -g eas-cli
eas login
eas init                                   # links the project, writes the project id
eas build -p ios --profile production      # signed build, EAS manages certificates
eas submit -p ios --profile production     # uploads to App Store Connect / TestFlight
```
Use `--profile preview` for an internal (ad hoc) build and `--profile development` for a dev client. The app screens already respect the notch and home indicator (safe-area insets, keyboard padding on iOS), account deletion is in Me, and nothing Android-only runs on iOS.
