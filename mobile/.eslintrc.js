module.exports = {
  root: true,
  extends: '@react-native',
  overrides: [
    // OTA tooling is plain Node ESM, not app code: Node globals on, and
    // no-bitwise off — CRC32 and ZIP headers are bitwise by definition.
    {
      files: ['scripts/*.mjs'],
      env: {node: true},
      parserOptions: {ecmaVersion: 'latest', sourceType: 'module'},
      rules: {'no-bitwise': 'off'},
    },
  ],
};
