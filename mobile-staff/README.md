# 826 Team (staff app)

Expo SDK 58, React Native, TypeScript, expo-router. The shop team's app: detailers move units through the queue, sales check sizes and payments, everyone with the right role reads clients and the Messenger inbox, managers and the owner see the overview. Android now, iOS prepared (not built). The Laravel API is in the repo root (`routes/api.php`, `/api/staff/*`).

Bundle id / package: `ph.radianceph.aap826.staff`, scheme `aap826team`, slug `826-team`, display name "826 Team".

## Run
```
cd mobile-staff
npm install
EXPO_PUBLIC_MOCK=1 npx expo start   # fake API, no server (sign in: ops@ / sales@ / manager@ / owner@example.test, password sample-password, owner code 123456)
```
| Variable | Default | Meaning |
|---|---|---|
| `EXPO_PUBLIC_API_URL` | `https://dran.dev/demo/826` | Laravel site, no trailing slash |
| `EXPO_PUBLIC_MOCK` | unset | `1` uses the built-in fake API (`src/api/mock.ts`) |
| `APP_VARIANT` | unset | `dev` allows cleartext http (local API). Never for release |

## Layout
- `app/` routes: `(tabs)` Queue, Today, Clients, Inbox, Overview, Me (the tab bar shows only what `GET /api/staff/me` `tabs` lists); `unit/[id]`, `client/[id]`, `thread/[id]`, `sign-in`.
- `src/api` typed client (`client.ts`), endpoints, shapes (`types.ts`, from the backend controllers), fake API (`mock.ts`, sample data, never in a normal build).
- `src/features` one file per screen; `src/ui` the design system parts; `src/lib` pure rules (tabs, stage chips, access, quick replies, dates in Manila time, photo shrink, token in secure store).
- The app invents nothing: money, contact, photos and the next step come from the API, which leaves out what a role may not see. Empty states say so.

## Checks
```
npx tsc --noEmit && npx jest && npx expo-doctor && npx expo export --platform android && npx expo export --platform ios
```

## Android release
```
scripts/build-apk.sh   # signed APK -> dist/aap826-team-<version>.apk
```
Toolchain: `source ~/Android/env.sh`. Signing reads `~/backups/826-staff-app/keystore.env` (`AAP826STAFF_*`, outside the repo, never committed). Publish the APK in the admin under App releases with app = 826 Team.

## iOS (prepared, not built)
`eas.json` has development, preview and production profiles; `app.json` has the bundle id, camera and photo usage texts and the privacy manifest. To build: `eas build -p ios --profile production` after the owner has an Apple developer account and fills `ascAppId` in `eas.json`. Nothing here has run on an iPhone.

## Not in this version
No push notifications, no email-code or passkey sign-in (the server tells the person to use an authenticator app), no per-branch review rating on the overview (the API does not send it).
