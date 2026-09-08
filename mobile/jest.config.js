module.exports = {
  preset: '@react-native/jest-preset',
  // Default preset excludes node_modules from transform except react-native
  // core packages. @react-native-async-storage and @react-navigation (added
  // Phase 1/4) both ship ESM and need the same treatment, or their imports
  // fail under Jest — this was latent since Phase 1's scaffold never had a
  // test that actually imported AppNavigator until now.
  transformIgnorePatterns: [
    'node_modules/(?!((jest-)?react-native|@react-native(-community)?|@react-native-async-storage|@react-navigation)/)',
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
