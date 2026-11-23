// Shared jest setup for every workspace: jest-expo, plus lucide-react-native from its CommonJS build (the ESM build does not transform).
const base = require('jest-expo/jest-preset');
// require() takes the package's CommonJS build (its `main`), which jest can run without transforming
const lucide = require.resolve('lucide-react-native');

module.exports = function jestConfig(rootDir, extra = {}) {
    return {
        ...base,
        rootDir,
        ...extra,
        setupFiles: [...(base.setupFiles ?? []), ...(extra.setupFiles ?? [])],
        moduleNameMapper: {
            ...(base.moduleNameMapper ?? {}),
            '^lucide-react-native$': lucide,
            ...(extra.moduleNameMapper ?? {}),
        },
    };
};
