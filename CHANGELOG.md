# CHANGELOG — GEDEFENSE WPR

> The product was named **VGT Omega** up to 4.2.2. It was renamed to **GeDefense WPR** in 4.2.3
> and now carries the GeDefense series identity. Historical entries below keep their original
> wording; the internal namespace (`VGT\Omega`), `vgt://` protocol, option keys and artifact format
> are unchanged.

## 4.2.3 — 2026-11-19 — GeDefense Series Rebrand

Rebranding release: VGT Omega joins the GeDefense security series as **GeDefense WPR**
(WordPress Runtime).

### Changed

* **Product name:** plugin header, admin menu label, console title, eyebrow, footer, CLI help,
  generated layer-0 kernel header, README, SECURITY, ARTIFACT_SPEC, CONTRIBUTING, verification
  ledger and textual code documentation now use **GeDefense WPR**.
* **Official logo:** the console masthead replaces the inline SVG seal
  (`vgt-icon vgt-seal__glyph`) with the official GeDefense shield (`assets/ge-defense.png`,
  vendored from the VGT brand asset library) rendered at 58×58 px. The README shows the same
  official logo in the brand block.
* **Distribution identity:** Composer package renamed to
  `visiongaiatechnology/ge-defense-wpr`, plugin ZIP/folder renamed to `ge-defense-wpr`,
  text domain `ge-defense-wpr`; admin menu slug stays `vgt-console` for bookmark/backward
  compatibility.
* **Product naming:** console code name is now `WORDPRESS RUNTIME`; README explicitly places the
  project in the GeDefense family alongside GeDefense Linux/GeDefenseWP work.
* **Version identity:** every version source reports 4.2.3 (VERSION, plugin header,
  `Plugin::VERSION`, layer-0 kernel, CLI, README, docs, tests).

### Unchanged

* No runtime, cryptography, storage, installer, authorization or artifact-format logic changed.
* `VGT\Omega` PHP namespace, `vgt://` stream protocol, `VGT_OMEGA_*` constants, option keys
  (`vgt_vault_*`), artifact format `vgt-artifact/4.2`, vault layout and HKDF domain strings are
  intentionally retained so existing installations and artifacts keep working.
* Console CSS tokens and white-primary typography from 4.2.1 remain unchanged.

### Migration

1. Deactivate/delete the old `vgt-omega` plugin folder (WordPress treats the new
   `ge-defense-wpr` folder as a separate plugin because the slug changed).
2. Install and activate `ge-defense-wpr-4.2.3.zip`.
3. Existing vault data, options and artifacts remain usable: no cryptographic names changed.
4. Re-enter any custom `VGT_OMEGA_*` constants only if you had added them; they are unchanged.

## 4.2.1 — 2026-11-19 — Console Readability & Contrast Patch

Patch release for the VGT security console. The rendered console previously used grey text tones
for primary headings and most body copy, which was hard to read on the dark glass layers.

### Changed

* **White primary typography:** `--vgt-text` is now `#ffffff`, `--vgt-text-2` is `#f3f7f8` and
  `--vgt-muted` is `#cfdbdf`. Explicit white rules cover `h1`–`h4`, the console title, panel /
  empty-state / banner titles, row names and counters.
* **Grey is tertiary only:** muted tones remain for tiny uppercase metadata and labels; descriptive
  copy, hints and subtitles use the near-white secondary tone.
* **Contrast evidence re-run:** `php tests/visual/audit-contrast.php` regenerated the audit with the
  new tokens (29/29 WCAG 2.2 AA pairs pass); rendered previews regenerated with version 4.2.1.
* **Version identity:** `VERSION`, plugin header, `Plugin::VERSION`, layer-0 kernel payload,
  `vgt-pack --version`, README, SECURITY/VERIFICATION and tests now consistently report 4.2.1.

### Unchanged

* No runtime, cryptography, storage, installer or authorization logic changed in this release.
* The 4.2.0 security controls and its internal re-audit baseline remain the security reference;
  the 4.2.1 delta is CSS tokens/typography plus version strings.

## 4.2.0 — 2026-11-19 — Security & Integrity Release

This is a full remediation release for the 4.1 audit. It changes the on-disk format, the key hierarchy and the bridge namespace; existing 4.0/4.1 **installed** artifacts are not mount-compatible and must be re-imported (their cloud packages can still be imported and are re-encrypted into the new format).

### Security — closed findings

| ID / chain | Severity | Fix |
|---|---|---|
| FIND-001 / CHAIN-001 — unencrypted `plugin.php` fallback + extraction race | Critical | Fallback loader deleted; hidden staging, validation, re-encryption, atomic publish; rotations cannot be observed half-installed |
| FIND-002 / CHAIN-002 — APCu plaintext leak, shared-memory poisoning, global cache flush | Critical | Process-local bounded plaintext cache only; APCu ciphertext-only and opt-in; prefix-scoped invalidation via `APCuIterator`; no `apcu_clear_cache()` |
| FIND-003 / CHAIN-003 — predictable "hardware key", missing AAD, self-brick | Critical | HKDF-SHA256(WordPress salts + 32-byte random pepper), per-artifact domain separation; mandatory AAD `vgt:v4:<id>:<path>`; SOFT_BIND default, STRICT_HW_BIND opt-in |
| FIND-004 / CHAIN-004 — `rotate_id` directory wipe, zip-slip variants, zip bombs | High | Strict artifact-id allowlist + membership; no `emptyDir()` at all; `SafeArchive` rejects `../`, `..\`, absolute/drive/ADS/NUL, symlinks, over-count/size/ratio; hard inflate budget |
| FIND-005 — vault inside webroot, `.htaccess` only covered `*.php` | High | Out-of-webroot auto-detection; neutral `.vgtblob` names; Apache 2.2/2.4 + IIS deny files; 96-bit CSPRNG ids instead of `uniqid()`; public assets mirrored separately |
| FIND-006 — MuDeployer path interpolation, non-strict `VGT_GUARD` search | Medium | `var_export()` path in generated kernel; strict prefix-at-offset-0 container parser; generated payload passes `php -l` |

Additional hardening shipped with the same release:

* Capability (`activate_plugins`) + nonce + per-user rate limit on every vault mutation; POST-only.
* Upload size measured with `filesize()` on the real temp file, never from `$_FILES['size']`.
* Typed exception hierarchy (`AppException` → `ValidationException`, `SecurityException`, `StorageException`, `AvailabilityException`, `ConflictException`) with opaque client references (`VGT-ERR-XXXXXXXX`); security/storage details only in `error_log`.
* Memory pre-flight before decrypt/inflate/re-encrypt; typed abort instead of OOM fatal on 64/128 MB workers.
* Raw/cleartext payloads: only non-executable public-asset extensions; executable and SVG payloads must be encrypted.
* Optional Ed25519 manifest signatures over deterministic canonical JSON; `VGT_OMEGA_REQUIRE_SIGNATURES` policy.
* Safe mode break-glass constant (`VGT_OMEGA_SAFE_MODE`) that pauses mounting but keeps rotation/deletion available.
* Encrypted root path fallback writes deny files even outside the webroot (defense in depth).

### Fixed

* **Namespace/bridge break:** all bridge types now live in `VGT\Omega\Bridge\*`; `WordPressAdapter` matches `BridgeInterface`/`EnvironmentInterface`; `class_exists()` checks and service-provider registration actually succeed.
* **Fallback boot container:** the container and bridge are now constructed in `vgt-os.php`/`Plugin`, not only in the generated mu-plugin. `$GLOBALS['vgt_container']` remains available for integrations.
* **Source identity:** version unified to **4.2.0** (plugin header, `Plugin::VERSION`, kernel payload, dashboard, README, composer, spec). License unified to AGPL-3.0-or-later; the contradicting MIT block in `vgt-os.php` was removed; every PHP file has an SPDX header.
* **Documentation truth:** README no longer claims AAD/HKDF/APCu/EU-law behavior that the code did not implement; broken `ARTIFACT_SPEC.md` link now resolves; file-structure section matches the real tree.
* **`LaravelAdapter.php` placeholder (17 bytes)** replaced by a real `BridgeInterface` implementation (events, filters, cache state, nonces, HTTP, assets) with guarded framework access.
* `url_stat()` now reports the real cleartext size and a stable manifest mtime (previously `size=1024`, `mtime=time()` on every call).
* Deleting/rotating an artifact no longer destroys unrelated data; metadata, public mirror and ciphertext caches are cleaned consistently.

### Added

* Reference encryptor **CLI** `bin/vgt-pack.php` with key generation, Ed25519 signing, classmap input and machine-readable exit codes.
* Normative **`ARTIFACT_SPEC.md`**: ZIP layout, v4.2 container bytes, manifest schema, AAD contexts, signature canonicalisation, limits, migration.
* **Ed25519** signature verification (`ext-sodium`) with deterministic canonical JSON.
* **Lazy classmap autoloading** via `vgt://` and **smart compression** (`VGT_RAW` < 16 KiB, `VGT_GZ` above) for faster cold requests on small servers.
* **Non-root diagnostics panel** in the console: layer-0 active, vault isolation, binding mode, signature policy, AES-GCM/Sodium/zip/PclZip/APCu, RAM headroom, mount/lock/fail counts, boot error state.
* **Glass 2100 security console** (new VGT design DNA): layered glass materiality, product-specific palette, accessible confirmation dialog instead of `confirm()`, local CSS/JS only, reduced-motion/transparency/forced-colors support, desktop/tablet/mobile compositions.
* **Standalone adapter** and test harness that exercises the real vault, installer, mounter and stream wrapper without WordPress.
* **Dependency-free test suite** `tests/run.php`: crypto, AAD, signatures, path jail, archive attacks, staging, no-plaintext-at-rest, install/rotate/delete E2E, stream wrapper, safe mode, layer-0 payload lint, line budgets, static anti-pattern gates.

### Changed

* Modular structure (each file ≤ 500 lines): `src/Bootstrap`, `src/Bridge/{Contracts,Core,Environments,Adapters,Providers}`, `src/Core/{Error,Exceptions}`, `src/System/{Deployment,Vault/{Crypto,Storage,Runtime,Support,Contracts}}`, `src/Admin`, `src/Tooling`, `src/UI/Dashboard`.
* Container is a true composition root with explicit constructor injection; no service locator in domain code.
* Vault metadata records `name`, `updated`, `entry`, `files`, `signed`, `format`; console shows timestamp and rotation state.
* `composer.json` with PSR-4 `VGT\Omega\ => src/` and AGPL-3.0-or-later metadata.

### Removed

* Unencrypted artifact fallback loader.
* Legacy APCu plaintext cache and global cache flush.
* `uniqid()` artifact ids, `emptyDir()` before validation, `sanitize_text_field()`-only rotation ids.
* 17-byte Laravel placeholder, dead `src/Adapters|Contracts|Core|Providers` layout, false README claims, MIT/AGPL header contradiction.

### Migration from 4.0/4.1

1. Cloud-imported packages (`artifacts created by an old encryptor`) can still be imported: the importer accepts the legacy container for transport and re-encrypts it into v4.2 (AAD, per-artifact HKDF key, `.vgtblob` storage).
2. Already-installed 4.0/4.1 artifacts cannot be mounted (their key hierarchy and file layout are gone). Re-import the original `.zip` with its cloud key; if the cloud key is lost, the artifact must be re-packaged from source.
3. The old `vgt_vault_keys` option is obsolete and ignored. `vgt_vault_pepper`/`vgt_vault_meta` are the new options.
4. If you had custom code calling `VGT\Bridge\…`, switch to `VGT\Omega\Bridge\…`; the public method signatures of `BridgeInterface` are unchanged.
5. `mu-plugins/vgt-omega-kernel.php` is regenerated atomically on the next admin request; delete the old manual kernel if you created one.

### Verification

Executed on 2026-11-19 (PHP 8.5.8 CLI, Windows; full ledger, snapshot recipe and limitations in [`docs/VERIFICATION.md`](docs/VERIFICATION.md)):

* `php tests/run.php` → **exit 0, 71/71 tests passed**, 0 failed, 0 skipped (crypto, AAD, signatures, path jail, archives, installer staging/rotation/no-plaintext, stream wrapper, safe mode, layer-0 kernel, facade, WordPress-shaped bootstrap, static gates, CLI).
* `php tests/lint.php` → **exit 0, 97 PHP files, 0 lint failures, 0 files above the 500-line budget**.
* `php tests/visual/audit-contrast.php` → 29/29 WCAG 2.2 AA contrast pairs; `audit-static.php` → 87/87 DOM/semantics + 32/32 source/design checks (rendered HTML previews only; browser screenshots `NOT_TESTED` because headless browser rendering is unavailable in the build environment).
* Independent adversarial re-audit (internal, raw report not published): **no security defect found in scope**, FIND-001…006 PASS, honest classification **GOLD**, release gate **ELIGIBLE_FOR_REVIEW** with conditions C-1…C-5 (notably a real staging-WordPress E2E before production). Frozen production snapshot: 77 PHP files, aggregate SHA-256 `f60d30a8d00478893ae9675a2b76c28f42a723200c64406c2d5c40016495c60c`. Public summary: [`docs/VERIFICATION.md`](docs/VERIFICATION.md).

Not tested in this environment: real WordPress boot, PclZip fallback, APCu branch, FPM OOM drills, real browser rendering/screen readers. These are listed as open items in [`docs/VERIFICATION.md`](docs/VERIFICATION.md) and are not claimed as verified.

---

## 4.1.0 — 2026 (historical, audited)

Declared itself "Platin" while shipping the six findings above, a broken bridge namespace, a 17-byte adapter placeholder and contradictory license/version metadata. Superseded by 4.2.0.

## 4.0.0 — 2026 (historical)

Initial "Diamant/Platin" encrypted loader. AES-256-GCM with a non-secret node fingerprint, no AAD, APCu plaintext cache, in-vault extraction, `uniqid()` ids. Superseded by 4.2.0.
