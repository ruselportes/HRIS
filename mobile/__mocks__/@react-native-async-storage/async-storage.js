/**
 * Manual Jest mock — AsyncStorage has no usable implementation under Jest
 * (no native runtime), and several modules under test reach it transitively
 * (getDatabase() resolves the SQLCipher key; repositories read device
 * credentials). A shared in-memory Map keeps those paths working without
 * every test file repeating the factory.
 *
 * Files that assert on storage calls keep their own jest.mock() factory,
 * which takes precedence over this file.
 *
 * @format
 */

const store = new Map();

module.exports = {
  __esModule: true,
  default: {
    getItem: jest.fn(async key => store.get(key) ?? null),
    setItem: jest.fn(async (key, value) => {
      store.set(key, value);
    }),
    removeItem: jest.fn(async key => {
      store.delete(key);
    }),
    getMany: jest.fn(async keys =>
      Object.fromEntries(keys.map(k => [k, store.get(k) ?? null])),
    ),
    setMany: jest.fn(async entries => {
      Object.entries(entries).forEach(([k, v]) => store.set(k, v));
    }),
    removeMany: jest.fn(async keys => keys.forEach(k => store.delete(k))),
    __clearStore: () => store.clear(),
  },
};
