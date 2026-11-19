# GEDEFENSE WPR — SECURITY MODEL, FINDINGS AND RECOVERY

**Version:** 4.2.3
**Security profile:** `GEDEFENSE_WP`
**Scope:** runtime, storage, installer, stream wrapper, admin surface, layer-0 deployment
**License:** AGPL-3.0-or-later

This document is the authoritative security record of the 4.2.x line. The security baseline is the 4.2.0 hardening release; 4.2.1 was a console readability patch and 4.2.3 is a branding/logo/version release — neither changed runtime or security logic. It states what is protected, how, what was broken before, what is still a residual risk, and how an operator recovers from a broken state. Claims without a pointer to code or a test are not made.

---

## 1. Reporting a vulnerability

Please **do not** open a public issue for security reports.

* Contact: `info@visiongaia.de`
* Include: affected version, minimal reproduction, impact, and whether the issue requires admin capability, a malicious artifact, or a compromised host.
* Do not test against systems you do not own or have written permission to test.

Supported for security fixes: `4.2.x`. The 4.0/4.1 line is end-of-life; the legacy format is only accepted as an *import* format in 4.2 (§7).

---

## 2. Threat model

### Assets

1. Proprietary PHP source code inside artifacts.
2. Cloud keys during package transport.
3. The server-derived artifact keys (never persisted).
4. Vault integrity (no swapped payloads, no injected code, no path escape).
5. Admin session integrity (CSRF, capability, rate limits).
6. Availability of the WordPress site (no OOM/DoS through archives, memory inflation or vault corruption).

### Adversaries considered

| Adversary | Capability | Handled by |
|---|---|---|
| Unauthenticated visitor | HTTP requests, enumerates URLs | Vault outside webroot, deny files, `.vgtblob` extension, 96-bit random ids, no plaintext on disk |
| Authenticated low-privilege user | Can browse admin, maybe upload files elsewhere | `activate_plugins` gate on every vault action, nonce, POST-only mutations |
| **Malicious artifact author / stolen cloud key** | Controls the ZIP, manifest and cloud-key encryption | Hidden staging, schema/limits, raw-php rejection, size+SHA-256 verification, AAD re-encryption, optional Ed25519 policy |
| Other tenants on shared hosting | Same PHP pool, separate webspace | no plaintext in APCu, no shared temp paths, process-local caches only, vault permissions 0700/0600 |
| Network attacker | MITM | HTTPS assumed at the host level; cloud-key encryption is application-layer defense in depth |
| Compromised host / root / malicious admin | Full file and DB access | **Out of scope.** WordPress salts + pepper are readable by PHP; an attacker who executes code with the web server user can decrypt mounted artifacts. |

### Explicit non-goals

* Protecting code against a server that is already compromised.
* Hiding code from a malicious administrator who can install arbitrary plugins — such an actor can read plaintext in RAM. Signature policy raises the bar for *supply-chain* compromise, not for a compromised admin.
* DRM or licensing enforcement. GeDefense WPR is a confidentiality/integrity runtime, not a license server.

---

## 3. Security invariants

| # | Invariant | Code | Verification |
|---|---|---|---|
| I1 | No artifact executes without an authenticated, schema-valid manifest. | `ArtifactMounter` | `tests/cases/06_runtime.php` (locked artifact), static gate (no plaintext fallback) |
| I2 | Every payload is AES-256-GCM protected and AAD-bound to artifact id + relative path. | `CryptoEngine`, `VaultFormat::aad()` | `tests/cases/02_crypto.php` |
| I3 | The ciphertext header must start at byte 0; no header search, no prefix tolerance. | `CryptoEngine::decrypt()` | `tests/cases/02_crypto.php` |
| I4 | Uploaded archives are validated and staged outside the mount scan before re-encryption. | `SafeArchive`, `ArtifactInstaller` | `tests/cases/04_archive.php`, `05_installer.php` |
| I5 | Paths from ZIP/manifest/stream are normalized and jailed; traversal in both slash directions and symlinks are rejected. | `PathGuard`, `SafeArchive`, `StreamWrapper` | `01_core`, `04_archive`, `06_runtime` |
| I6 | No cleartext payload is persisted. | `ArtifactInstaller`, blob naming | `05_installer.php` scans every vault file for plaintext markers |
| I7 | No cleartext is stored in shared memory. | `StreamWrapper` (process-local cache; APCu ciphertext only) | static gate + code review; APCu path `NOT_TESTED` (no APCu in this environment) |
| I8 | Archive abuse (zip-slip, symlink, bomb, entry count, total size) is rejected before extraction. | `SafeArchive`, `ArchiveLimits` | `04_archive.php` |
| I9 | The vault is placed outside the document root when possible and is always paired with Apache/IIS deny files. | `VaultLocator`, `VaultLockdown` | `07_bridge_deploy.php`, `05_installer.php` |
| I10 | Admin mutations require capability + nonce; errors never leak internals. | `ArtifactController`, `ExceptionReporter` | `01_core.php`, `07_bridge_deploy.php`; HTTP glue `NOT_TESTED` (no WordPress runtime here) |
| I11 | Ed25519 signatures are verified over a deterministic canonical payload; policy can require signatures. | `Manifest`, `CanonicalJson`, `SignatureVerifier` | `03_manifest_signature.php`, `05_installer.php` |
| I12 | Resource exhaustion fails typed and safe (memory pre-flight, size caps, typed availability errors). | `MemoryGuard`, caps in `VaultFormat` | `01_core` (guard), archive/installer tests; production `memory_limit` behaviour `NOT_TESTED` across PHP configs |

---

## 4. Findings of the 4.1 audit — closure record

The 4.1 repository was published as "Platin/Diamant" while containing the findings below. All six findings are closed at the source of the root cause, not merely mitigated.

### FIND-001 (Critical / CHAIN-001) — unencrypted execution fallback + extraction race

* **Was:** `mountArtifacts()` executed `plugin.php` from any vault directory without a manifest (`require_once $dir.'/plugin.php'`). `installArtifact()` extracted the ZIP directly into the live vault and only afterwards validated the cloud key and re-encrypted; a parallel request could execute the plaintext file during that window.
* **Fix:** the fallback is deleted. Mounting requires a decrypted, authenticated manifest with blobs. The installer extracts into `.staging/install_<random>/incoming`, validates, re-encrypts and publishes with `rename()`; rotations swap old and new directories atomically with rollback.
* **Evidence:** `ArtifactMounter::mountAll()`, `ArtifactInstaller::install()`, `tests/cases/06_runtime.php` (corrupt manifest ⇒ locked, never executed), `tests/cases/05_installer.php`.

### FIND-002 (Critical / CHAIN-002) — APCu plaintext leak and shared-memory poisoning

* **Was:** `StreamWrapper::stream_open()` stored decrypted PHP source in APCu for 3600 s; any process in the same FPM/APCu pool could dump it. `apcu_clear_cache()` on upload/delete flushed the whole shared cache of unrelated applications; `url_stat()` cached with an unauthenticated key.
* **Fix:** decrypted bytes live only in a bounded process-local static cache. APCu caching is opt-in, ciphertext-only (`VaultFormat::APCU_BLOB_PREFIX`, `APCU_BLOB_TTL`), validated by GCM on use, and invalidated by prefix via `APCuIterator` — never with `apcu_clear_cache()`. `url_stat()` uses the authenticated manifest and a stable mtime.
* **Evidence:** `StreamWrapper` (no plaintext store), `ArtifactRepository` (prefix invalidation), static gate in `08_static_gates.php`; APCu branch `NOT_TESTED` (extension absent).

### FIND-003 (Critical / CHAIN-003) — predictable key, no AAD, self-brick

* **Was:** the AES key was derived from `php_uname`, `fileinode(ABSPATH)`, `DB_NAME`, `DB_HOST` — none secret. `openssl_encrypt/decrypt` never received the AAD argument despite README promises. A hoster migration changed the inode and destroyed all artifacts.
* **Fix:** HKDF-SHA256 over `AUTH_KEY`/`SECURE_AUTH_KEY`/`LOGGED_IN_KEY`/`NONCE_KEY` plus a 32-byte random pepper (provisioned once, race-safe) and a per-artifact HKDF domain. Mandatory AAD for all operations. `SOFT_BIND` (default) survives inode/domain/storage migration; `STRICT_HW_BIND` is opt-in. Pepper missing/unreadable ⇒ import refuses to encrypt rather than creating unrecoverable artifacts.
* **Evidence:** `KeyDerivation`, `CryptoEngine`, `VaultFormat::aad()`, `tests/cases/02_crypto.php` (AAD mismatch fails), `03_manifest_signature.php`.

### FIND-004 (High / CHAIN-004) — directory wipe, zip-slip, zip bomb

* **Was:** `rotate_id` was sanitized with `sanitize_text_field()` only (no `basename`, no allowlist) and the target directory was emptied **before** the ZIP was validated: `rotate_id=../../plugins/woocommerce` wiped arbitrary directories. The zip-slip check missed backslashes, NUL bytes and symlink attributes. `gzdecode()` had no length cap.
* **Fix:** artifact and rotation ids are strict (`^vgt_[a-f0-9]{13}$` legacy migration / `^vgt_[a-f0-9]{24}$` new) plus membership checks. No directory is emptied at all: the new artifact is staged and swapped in atomically. `SafeArchive` rejects absolute/traversal paths in both slash directions, drive letters, colons, NUL/control characters, symlinks, overlong/deep paths, case collisions, over-count, oversized/total/ratio violations, and verifies the extracted tree. `gzdecode()` receives a hard max length. Rotation is membership-checked and cannot target a path outside the vault.
* **Evidence:** `PathGuard`, `SafeArchive`, `ArtifactInstaller::install()`, `tests/cases/01_core.php`, `04_archive.php`, `05_installer.php`.

### FIND-005 (High) — vault exposed in the webroot, `.htaccess` only covers `*.php`

* **Was:** the vault lived under `wp-content/uploads/vgt-vault`, `.htaccess` denied only `*.php`, the manifest `.vgt` files were directly downloadable, artifact ids were `uniqid()` (timestamp) and printed in page source.
* **Fix:** out-of-webroot auto-detection (`dirname(ABSPATH)/.vgt-vault` preferred, writability-proven) with webroot fallback. On disk every file has a random 96-bit/128-bit id and the `.vgtblob` extension — no source names, no `.php` names. Apache 2.2+2.4 dual-syntax deny rules, IIS `web.config`, and `index.php` are written for every vault location. Artifact ids are `vgt_` + 96-bit CSPRNG. Encrypted assets are not exposed over HTTP; raw public assets are mirrored to `uploads/vgt-public/<id>/…` by explicit design.
* **Evidence:** `VaultLocator`, `VaultLockdown`, `PathGuard::newArtifactId()`, `ArtifactRepository`, `tests/cases/07_bridge_deploy.php` (isolation + deny files), `05_installer.php` (blob naming/mirror).

### FIND-006 (Medium) — MuDeployer string interpolation + non-strict header cut

* **Was:** `'{$plugin_path}'` interpolation in generated PHP; `decryptPayload()` searched for `VGT_GUARD` anywhere and cut even if absent.
* **Fix:** the kernel payload is built with `var_export()` for the path and passes `php -l`; the generated file is written atomically and idempotently. The container parser requires the literal magic prefix at offset 0. The legacy "search anywhere" code path is gone.
* **Evidence:** `MuKernelPayload`, `MuDeployer`, `CryptoEngine::decrypt()`, `tests/cases/07_bridge_deploy.php` (payload + `php -l`), `02_crypto.php` (prefix rejected).

### Source identity (VGT-SID-001) — resolved

* Version conflict (`vgt-os.php` 4.1.0 Platin, `MuDeployer` 4.0.0 Diamant, README 4.0.0, Dashboard "Core 4.0") is resolved to **4.2.0** (security baseline) and **4.2.3** (console readability patch) across the plugin header, `Plugin::VERSION`, kernel payload, console, composer metadata, README, CHANGELOG and ARTIFACT_SPEC.
* License conflict: the plugin header declared "AGPLv3" and embedded an MIT permission block while `LICENSE` is AGPLv3. The MIT block is removed; every source file carries `SPDX-License-Identifier: AGPL-3.0-or-later`.
* The 17-byte `LaravelAdapter.php` placeholder is replaced by a real adapter; the bridge namespace break (`VGT\Bridge` vs `VGT\Omega`) is resolved to `VGT\Omega\Bridge\*`; the service provider is actually reachable; the fallback boot path now builds the container (previously only the generated mu-plugin did).

---

## 5. Operator hardening checklist

1. Keep WordPress, PHP and OpenSSL patched; enable HTTPS/HSTS at the web server.
2. Put the vault outside the document root (the locator does this automatically; verify in the console diagnostics).
3. Define `VGT_OMEGA_REQUIRE_SIGNATURES` and sign release artifacts in CI with the Ed25519 key; keep the secret key offline.
4. Treat cloud keys as one-time secrets: generate per release, hand over out-of-band, delete after import.
5. Use `STRICT_HW_BIND` only on servers you control and have documented migration procedures for.
6. Back up the **database** (pepper option) and `wp-config.php` salts together — otherwise installed artifacts are unrecoverable after a restore that only restores files.
7. Keep `wp-content/mu-plugins` writable for layer-0 deployment; monitor the console `layer-0` indicator.
8. Do not grant `activate_plugins` broadly; the vault console is a code-execution surface by nature.
9. For Apache/nginx/IIS: deny direct access to the entire vault path at the server level as a second layer (`.htaccess`/`web.config` are written, but nginx requires explicit configuration when the vault is inside the webroot).

Example nginx location (defense in depth, webroot fallback only):

```nginx
location ~* /wp-content/vgt-vault/ { deny all; return 403; }
location ~* /wp-content/uploads/vgt-vault/ { deny all; return 403; }
```

---

## 6. Safe mode and recovery

### Break-glass: pause mounting without locking yourself out

Add to `wp-config.php`:

```php
define('VGT_OMEGA_SAFE_MODE', true);
```

Result: no artifact is mounted or executed; the console stays usable; artifacts are listed as locked; **rotation and deletion remain possible**. This is the recovery path when a bad artifact breaks the site (fatal error/side effect at boot).

### Recover a locked artifact

Locked means: the installed manifest could not be decrypted/authenticated. Causes and recovery:

| Cause | Recovery |
|---|---|
| WordPress salts rotated (`AUTH_KEY` etc.) | Restore the old `wp-config.php` values, or re-import the artifact with its cloud key (rotation restores the artifact with the new key hierarchy). |
| Pepper option missing/restored from an old backup | Restore the matching DB backup, or re-import the package. |
| `VGT_OMEGA_BINDING_MODE` changed soft↔strict | Set it back, or re-import. |
| Corrupted vault file | Re-import with the cloud key. If the key is lost, the payload cannot be recovered — by design. |
| Node moved with strict binding | Restore the original node or re-import. |

### Remove a malicious/broken artifact

Console → Vault → *Purge*. The artifact directory, metadata, mirrored public assets and ciphertext cache entries are removed. If the console is unreachable, delete the artifact directory manually (`<vault>/artifacts/<id>/`) — the mounter will simply not find a manifest afterwards.

### Rotate the key hierarchy after suspected exposure

1. Enable safe mode.
2. Re-import every artifact with fresh cloud keys (new packages generated from the original sources).
3. Rotate the WordPress salts and delete the pepper option; the next import provisions a new random pepper.
4. Disable safe mode and verify mounting.

There is deliberately no "silent bypass" and no signature/TLS disable switch.

---

## 7. Host compatibility and known limitations

| Environment | Behaviour |
|---|---|
| Apache 2.2/2.4 | `.htaccess` denies the whole vault (dual syntax) |
| IIS | `web.config` authorization deny; `.vgtblob` extension is not mapped to PHP |
| Nginx / Caddy / LiteSpeed without `.htaccess` | Blobs are non-executable ciphertext with random names; add a server-level deny rule for the webroot fallback path |
| No `ext-zip` | WordPress `PclZip` backend with identical validation; `NOT_TESTED` in this environment (no WordPress install), code path reviewed |
| No APCu | Supported; APCu is only an opt-in ciphertext cache |
| 64–128 MB `memory_limit` | Memory pre-flight aborts oversized operations with a typed error instead of OOM; tested logic, production configs `NOT_TESTED` |
| OPcache | Userland stream wrappers are not OPcache-cached. Mitigated by classmap lazy loading + `VGT_RAW`; **not eliminated** |

### Residual risks

1. **WordPress salts/pepper are readable by any PHP code on the host.** A compromised plugin or admin can derive the artifact key and read mounted plaintext from RAM. The vault protects data at rest against server/file theft, not against a compromised runtime.
2. **Admins are trusted.** The console allows uploading and executing encrypted code; an admin who installs a malicious artifact is, by definition, installing code. Signature policy protects against *stolen distribution channels*, not against a malicious admin.
3. **OPcache performance limitation** (above) is architectural to PHP userland stream wrappers.
4. **Cloud key transport** is only as good as the channel used to deliver it. Use a separate channel from the package file and rotate per release.
5. **`STRICT_HW_BIND`** trades clone resistance for migration fragility; it is opt-in.
6. **No independent audit/certification** of this release; see [`docs/VERIFICATION.md`](docs/VERIFICATION.md) for the exact verification evidence and what was not tested.

---

## 8. Verification evidence

The following commands are the reproducible release evidence. Actual results on the verification host (2026-11-19, PHP 8.5.8 CLI, no WordPress runtime):

| Command | Result |
|---|---|
| `php tests/run.php` | exit 0 · total=71 · passed=71 · failed=0 · skipped=0 |
| `php tests/lint.php` | exit 0 · files=97 · lint_failures=0 · line_budget_failures=0 |
| `php tests/visual/render-dashboard.php` | exit 0 · 3 console preview documents rendered via the production render path |
| `php tests/visual/audit-contrast.php` | exit 0 · 29 contrast pairs, 0 failures |
| `php tests/visual/audit-static.php` | exit 0 · 87 DOM/semantics checks, 32 source/design checks, 0 failures |
| `php bin/vgt-pack.php --version` | `vgt-pack 4.2.3 (vgt-artifact/4.2)` |
| Signed package round-trip | covered by test `vgt-pack produces an installable, signed package` (exit 0) |

Independent adversarial re-check of FIND-001…006 on the final tree: an internal adversarial re-audit was performed against the frozen production snapshot. Result: **no security defect found in scope; all six findings PASS (closed at root cause, with independent probes); honest classification GOLD; release gate ELIGIBLE_FOR_REVIEW with conditions C-1…C-5** (real staging-WordPress E2E, APCu/PclZip/FPM paths, web-server deny enforcement, supply-chain configuration, separate legal review). The raw internal audit artifact is not published; the public summary, snapshot recipe and reproducible ledger are in [`docs/VERIFICATION.md`](docs/VERIFICATION.md). It is an internal re-audit, not third-party certification.

What is **not** covered by this repository's evidence:

* a real WordPress boot with the plugin activated (no WordPress runtime here) — adapter glue is statically verified and partially unit-tested with host stubs;
* PclZip import path (no WordPress class available);
* APCu ciphertext cache path (extension absent);
* multi-process race drills and real 64 MB FPM pools;
* browser-rendered visual QA (headless Chromium cannot start in the confined build
  environment: browser process/IPC creation is denied, so no screenshot can be produced).
  Rendered HTML previews of the real UI path and a static design/accessibility audit are
  provided under `tests/visual/` and `docs/visual/`; real screenshots, touch testing and a
  screen-reader audit are `NOT_TESTED` and must be performed on an unconfined staging host.
