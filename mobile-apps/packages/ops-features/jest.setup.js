// Every screen test runs against the built-in fake API (src/api/mock.ts), never a server.
process.env.EXPO_PUBLIC_MOCK = '1';
