module.exports = {
  preset: '@react-native/jest-preset',
  // Default preset excludes node_modules from transform except react-native
  // core packages. Every scope added here ships ESM and fails to parse under
  // Jest without it: @react-native-async-storage and @react-navigation
  // (Phase 1/4), @op-engineering/op-sqlite (Phase 4), @noble/hashes
  // (Phase 5 HMAC). Add new native/ESM deps to this list as they arrive.
  transformIgnorePatterns: [
    'node_modules/(?!((jest-)?react-native|@react-native(-community)?|@react-native-async-storage|@react-navigation|@op-engineering|@noble)/)',
  ],
  // Native module — no real bridge under Jest, so redirect to the package's
  // own official mock (ForemanHomeScreen reads network status via NetInfo).
  // The mock file just exports an object; it has to be wired via
  // moduleNameMapper, not setupFiles, to actually intercept the import.
  moduleNameMapper: {
    '^@react-native-community/netinfo$':
      '<rootDir>/node_modules/@react-native-community/netinfo/jest/netinfo-mock.js',
  },
};
