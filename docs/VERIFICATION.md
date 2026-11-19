# VGT Omega 4.2.1 — Verification Ledger & Limitations

This page documents what was actually verified for the 4.2.1 release, how to reproduce it, and
what was **not** verified. A green test suite is corroboration, not a security guarantee.

---

## 1. Release identity

| Item | Value |
|---|---|
| Version | **4.2.1** |
| Central version source | `VERSION` file, mirrored by the plugin header and `Plugin::VERSION` |
| Artifact format | `vgt-artifact/4.2` |
| License | AGPL-3.0-or-later |
| Production source snapshot | **77 PHP files** (75 under `src/`, 1 under `bin/`, plus `vgt-os.php`) |
| Snapshot recipe | PHP files under `src/` and `bin/` plus `vgt-os.php`, sorted by absolute path; aggregate = SHA-256 of the concatenated lowercase per-file SHA-256 hex digests |
| Aggregate SHA-256 | `542e6b398873964b17e4df1cf185415c4cae061cf67f2387b6719c2f33eb7283` |

Reproduce the aggregate (PowerShell):

```powershell
$root  = (Get-Location).Path
$paths = @(Get-ChildItem -Recurse -File -Filter *.php src, bin | ForEach-Object FullName)
$paths += Join-Path $root 'vgt-os.php'
$lines  = $paths | Sort-Object | ForEach-Object { (Get-FileHash -Algorithm SHA256 -LiteralPath $_).Hash.ToLower() }
$sha    = [System.Security.Cryptography.SHA256]::Create()
$hash   = $sha.ComputeHash([Text.Encoding]::UTF8.GetBytes(($lines -join '')))
([BitConverter]::ToString($hash)).Replace('-', '').ToLower()
```

---

## 2. Executed gates

Environment: Windows, PHP 8.5.8 CLI (ZTS), OpenSSL 3.5.7, `sodium` + `zip` present, APCu absent,
no WordPress runtime, no Composer, no network access during tests.

| Command | Observed result | Exit |
|---|---|---|
| `php tests/run.php` | `total=71 passed=71 failed=0 skipped=0` | **0** |
| `php tests/lint.php` | `files=97 lint_failures=0 line_budget_failures=0` | **0** |
| `php tests/isolated/safe_mode.php` | `SAFE_MODE_OK mounted=0 locked=1` | 0 |
| `php tests/isolated/wp_boot.php` | `WP_BOOT_OK platform=wordpress / plugin format=vgt-artifact/4.2 version=4.2.1` (WordPress-shaped stubs, not a real WordPress boot) | 0 |
| `php bin/vgt-pack.php --version` | `vgt-pack 4.2.1 (vgt-artifact/4.2)` | 0 |
| `php bin/vgt-pack.php --keygen` | valid 64-hex cloud key + Ed25519 keypair | 0 |
| Signed package round-trip | pack → ZIP inspect → installer import, `signed=true` (part of the suite) | 0 |
| `php tests/visual/render-dashboard.php` | 3 console preview documents rendered via the production render path | 0 |
| `php tests/visual/audit-contrast.php` | 29 contrast pairs, 0 failures | 0 |
| `php tests/visual/audit-static.php` | 87 DOM/semantics checks + 32 source/design checks, 0 failures | 0 |

### Suite coverage

* AES-256-GCM round-trips, AAD mismatch, tamper/truncation, strict header position, legacy import.
* Manifest schema attacks, unknown fields, traversal, raw-executable rejection, classmap targets.
* Ed25519 signing/verification/policy; unsigned rejection when signatures are required.
* Path jail (absolute, `..` in both slash directions, drive letters, NUL/control characters).
* Archive attacks: zip-slip, symlink entries, compression bombs, entry/size/ratio limits.
* Installer: hidden staging, no plaintext at rest, rotation, deletion, wrong-key cleanup,
  undeclared-file rejection, raw-public-asset mirroring.
* Mounting: authenticated manifest requirement, locked state, `vgt://` reads, traversal rejection,
  synthetic directory listing, real `url_stat` size and stable mtime, lazy classmap loading,
  ciphertext tamper rejection.
* Safe mode, layer-0 kernel generation/linting/atomic deployment, facade boot and diagnostics,
  admin notice transport, WordPress-shaped bootstrap.
* Static release gates: line budget, forbidden anti-patterns, legacy removal, source/version
  identity, composer manifest, documentation links, CDN scan, emoji scan, `php -l`.

### Independent adversarial re-check

A separate adversarial verification pass was performed against the frozen **4.2.0 security
baseline** source snapshot: 69 independent attack probes (AAD/ciphertext swap, tamper/truncation,
wrong-key cleanup, signature policy, archive attacks, stream traversal, kernel path-injection,
no-plaintext-at-rest), 9 HTTP-adapter scenarios and the isolated subprocesses above.

Result: **no security defect found in scope**; all six findings of the 4.1 audit are closed at the
root cause. Honest classification: **GOLD**. PLATIN is not justified because core target-platform
paths are `NOT_TESTED` (below), not because a vulnerability was found. The raw internal audit
artifact is not part of the public repository.

**Scope of the 4.2.1 delta:** only console CSS tokens/typography (white primary text) and version
strings changed. No runtime, cryptography, storage, installer or authorization code was modified,
so the 4.2.0 security-baseline evidence remains applicable; the 4.2.1 change itself was not subject
to a new security audit.

---

## 3. Not verified in this environment (honest list)

| Item | Reason | Status |
|---|---|---|
| Real WordPress activation + real multipart `admin-post.php` upload | No WordPress runtime here | `NOT_TESTED` |
| PclZip fallback extraction | Requires WordPress' bundled PclZip class | `NOT_TESTED` |
| APCu ciphertext cache + prefix invalidation | APCu extension absent | `NOT_TESTED` |
| Real 64/128 MB FPM OOM drill | No FPM pool | `NOT_TESTED` |
| Browser screenshots / live rendering / screen reader / touch / 200 % zoom | Headless browsers cannot start in the confined build environment | `VISUAL_QA=NOT_TESTED` |
| Real Apache/IIS/nginx deny enforcement, out-of-webroot writability on POSIX hosting | Windows-only build host | `NOT_TESTED` |
| Concurrent rotation / TOCTOU races | No multi-process staging host | `NOT_TESTED` |
| OPcache measurement, `STRICT_HW_BIND` migration, recovery drills, real 4.0/4.1 vault migration | No staging data/host | `NOT_TESTED` |
| Multisite network semantics, SAST/DAST/SCA, release provenance/SLSA | Out of scope of this build | `NOT_TESTED` / `NOT RUN` |

Rendered console previews and static design/accessibility audits are provided in
[`docs/visual/`](visual/) as a substitute for pixel QA. They are real output of the production
render path, not screenshots.

---

## 4. Pre-production checklist

Before operating VGT Omega in production:

1. Run a staging WordPress end-to-end test: activate, deploy a signed artifact through the real
   upload form, read it via `vgt://`, rotate it, delete it, enable/disable safe mode, verify layer-0.
2. Verify the web server denies the vault path (especially the webroot fallback) on the actual host.
3. Run one import on the smallest memory limit you intend to support.
4. Configure `VGT_OMEGA_REQUIRE_SIGNATURES`, keep the Ed25519 secret offline and use one-time cloud
   keys delivered out-of-band.
5. Back up `wp-config.php` salts and the database pepper **together**; otherwise installed
   artifacts are unrecoverable after a partial restore.

Recovery and migration procedures: [`SECURITY.md`](../SECURITY.md).
