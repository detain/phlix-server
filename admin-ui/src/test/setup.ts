/**
 * Vitest setup — runs before every test file.
 *
 * Registers `@testing-library/jest-dom` matchers and clears
 * `localStorage` between tests so token-store state never leaks across
 * cases.
 */
import '@testing-library/jest-dom/vitest';
import { afterEach } from 'vitest';
import { cleanup } from '@testing-library/react';

afterEach(() => {
  cleanup();
  if (typeof localStorage !== 'undefined') {
    localStorage.clear();
  }
});
