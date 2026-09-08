/**
 * Manual Jest mock — op-sqlite is JSI-based and throws on import when no
 * native module is linked (there's no real bridge under Jest, and the
 * package ships no official mock, unlike NetInfo). Only what this project
 * actually calls (open, db.execute, db.transaction) is implemented; App.test.tsx
 * is a render-once smoke test, not a DB-behavior test — those live in
 * attendanceLogic.test.ts against pure functions instead.
 *
 * @format
 */

function makeFakeDb() {
  return {
    execute: jest.fn().mockResolvedValue({rows: [], rowsAffected: 0}),
    executeSync: jest.fn().mockReturnValue({rows: [], rowsAffected: 0}),
    transaction: jest.fn(async fn => {
      await fn({
        execute: jest.fn().mockResolvedValue({rows: [], rowsAffected: 0}),
        commit: jest.fn(),
        rollback: jest.fn(),
      });
    }),
    close: jest.fn(),
  };
}

module.exports = {
  open: jest.fn(() => makeFakeDb()),
  openAsync: jest.fn(async () => makeFakeDb()),
  isSQLCipher: jest.fn(() => false),
  isLibsql: jest.fn(() => false),
  isTurso: jest.fn(() => false),
  isIOSEmbedded: jest.fn(() => false),
};
