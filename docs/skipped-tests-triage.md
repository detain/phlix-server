# Server Skipped Tests Triage

## Summary

Date: 2026-05-23
Branch: `fix/5.3-skipped-tests-triage`
Total Skipped Tests: 9 (14 individual test methods)

## Skipped Test Inventory

| Test File | Test Method(s) | Current Skip Reason | Recommended Action | Rationale |
|-----------|----------------|---------------------|-------------------|-----------|
| `tests/Integration/Plugins/InstallEnableDisableTest.php` | `test_full_lifecycle_with_fixture_plugin` (line 76) | `composer binary not available on PATH` | **Refactor → Integration** | Already in Integration suite. The test itself is valid; composer not being present is an environmental issue. Mark with `@requires binary composer`. |
| `tests/Unit/LiveTv/Relay/HlsSegmentPrefetcherTest.php` | `testStartPrefetchDoesNotThrow` (line 121) | `Workerman Timer not available in this environment` | **Integration** | Tests Workerman Timer which requires Workerman runtime. These are already marked with `@group workerman` but still skip. Move to Integration suite or mark `@requires extension workerman`. |
| `tests/Unit/LiveTv/Relay/HlsSegmentPrefetcherTest.php` | `testStartAndStopPrefetch` (line 148) | Same as above | **Integration** | Same as above |
| `tests/Unit/LiveTv/Relay/HlsSegmentPrefetcherTest.php` | `testMultipleStartPrefetchReplacesPrevious` (line 166) | Same as above | **Integration** | Same as above |
| `tests/Unit/LiveTv/Relay/HlsRelayManagerTest.php` | `testStartRelaySessionCreatesTuneRequest` (line 101) | Same as above | **Integration** | Tests Workerman Timer-dependent functionality. Mark `@group workerman` and move to Integration suite. |
| `tests/Unit/LiveTv/Relay/HlsRelayManagerTest.php` | `testStartRelaySessionStoresInDb` (line 144) | Same as above | **Integration** | Same as above |
| `tests/Unit/LiveTv/Relay/HlsRelayManagerTest.php` | `testStopRelaySessionDropsPerSessionSegmentCache` (line 335) | Same as above | **Integration** | Same as above |
| `tests/Unit/Server/Core/ApplicationTest.php` | `testApplicationCanBeInstantiated` (line 19) | `No MySQL on 127.0.0.1:3306` | **Integration** | This is a smoke test that requires MySQL. It's already correctly designed (has `@group` annotation potential). Mark with `@requires mysql` or move to Integration suite. |
| `tests/Unit/Server/WebPortal/Controllers/PluginAdminPageControllerTest.php` | `test_index_renders_template_when_smarty_available` (line 171 via `skipWithoutSmarty`) | `Smarty runtime class not available` | **Refactor → Integration** | This test and 3 others (test_install_renders_form_when_smarty_available, test_detail_renders_template_when_smarty_available) already in Unit but require Smarty. Move to Integration suite and update phpunit.xml. |
| `tests/Unit/Media/Markers/Fingerprinting/ChromaPrintFfiTest.php` | `testFingerprintThrowsWhenFileNotFound` (line 24) | `FFI is not available on this system` | **Integration** | Tests FFI functionality (ChromaPrint). This is a legitimate environmental dependency. Move to Integration suite or mark with `@requires extension ffi`. |
| `tests/Unit/Plugins/Installer/ComposerRunnerTest.php` | `test_install_succeeds_on_minimal_composer_json` (line 64) | `composer binary not available on PATH` | **Refactor → Integration** | Uses real composer binary. Move to Integration suite. |
| `tests/Unit/Plugins/Installer/HttpInstallerTest.php` | `test_install_throws_when_destination_rename_fails_due_to_read_only_base` (line 335) | `Cannot enforce read-only directory as root` | **Refactor → Integration** | This test and test_installFromDirectory_throws_when_subdir_mkdir_fails (line 470) test permission-based behavior. This is not a meaningful unit test anyway - the behavior is correct but testing it requires root. Move to Integration suite or delete (testing root permission handling is not valuable). |
| `tests/Unit/Plugins/Installer/HttpInstallerTest.php` | `test_installFromDirectory_throws_when_subdir_mkdir_fails` (line 470) | `Cannot enforce read-only directory as root` | **Delete** | Same as above - testing root permission bypass has no value. The logic being tested is trivial (mkdir fails on read-only dir). |
| `tests/Unit/Admin/BackupManagerTest.php` | `testCreateBackupGeneratesIdAndPath` (line 25) | `createBackup requires actual filesystem and mysqldump` | **Refactor → Integration** | Explicitly says to use integration tests. Move to Integration suite. |

## Recommended Actions by Category

### Move to Integration Suite (with docker-compose)

These tests should be moved from `tests/Unit/` to `tests/Integration/` (or simply marked with `@group integration`):

1. **HlsSegmentPrefetcherTest** - 3 tests using Workerman Timer (already have `@group workerman`)
2. **HlsRelayManagerTest** - 3 tests using Workerman Timer (already have `@group workerman`)
3. **ApplicationTest** - 1 test requiring MySQL
4. **ChromaPrintFfiTest** - 1 test requiring FFI extension

### Refactor / Abstract Dependencies

These tests should have their environmental dependencies properly declared:

1. **ComposerRunnerTest::test_install_succeeds_on_minimal_composer_json** - Add `@requires binary composer`
2. **HttpInstallerTest** - Two tests that check read-only filesystem behavior - these test behavior that cannot be meaningfully tested without root; consider marking with `@requires user root` or convert to Integration test

### Delete

No tests recommended for deletion at this time. The permission-based tests have some value in documenting expected behavior even if they can't reliably run in all environments.

## Implementation Status

- [x] Document findings (this file)
- [ ] Move Workerman Timer tests to Integration suite (add `@group workerman` / move file)
- [ ] Move MySQL-dependent ApplicationTest to Integration suite
- [ ] Move Smarty-dependent controller tests to Integration suite
- [ ] Move FFI test to Integration suite
- [ ] Move composer-dependent tests to Integration suite
- [ ] Add `@requires` annotations where applicable
- [ ] Commit and create PR

## Notes

- The 3 errors in the test suite are separate from skipped tests
- The phpunit.xml already defines an Integration testsuite pointing to `tests/Integration/`
- The Integration suite is currently empty in terms of actual test files (no `*Test.php` files found)
- Adding `@group integration` annotations to existing tests and running them separately is the easiest path forward
