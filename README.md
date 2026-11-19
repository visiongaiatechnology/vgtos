<p align="center">
  <img src="assets/sirdu.png" alt="Sir Quacksec — the VisionGaia Technology mascot" width="240">
</p>

<h1 align="center">VGT Omega System</h1>

<p align="center">
  <strong>Sovereign, encrypted artifact runtime for WordPress.</strong><br>
  Load proprietary PHP artifacts from authenticated RAM — never from cleartext disk.
</p>

<p align="center">
  <a href="LICENSE"><img alt="License: AGPL-3.0-or-later" src="https://img.shields.io/badge/License-AGPL--3.0--or--later-0f766e?style=for-the-badge"></a>
  <a href="https://www.php.net"><img alt="PHP 8.1+" src="https://img.shields.io/badge/PHP-8.1%2B-4f5b93?style=for-the-badge"></a>
  <a href="https://wordpress.org"><img alt="WordPress 6.0+" src="https://img.shields.io/badge/WordPress-6.0%2B-21759b?style=for-the-badge"></a>
  <img alt="Version 4.2.0" src="https://img.shields.io/badge/Version-4.2.0-b0895b?style=for-the-badge">
  <a href="https://github.com/visiongaiatechnology/vgtos/actions/workflows/quality.yml"><img alt="Quality gate" src="https://github.com/visiongaiatechnology/vgtos/actions/workflows/quality.yml/badge.svg"></a>
</p>

<p align="center">
  <a href="https://visiongaiatechnology.de"><strong>VisionGaia Technology</strong></a> ·
  <a href="CHANGELOG.md">Changelog</a> ·
  <a href="ARTIFACT_SPEC.md">Artifact Spec</a> ·
  <a href="SECURITY.md">Security</a> ·
  <a href="docs/VERIFICATION.md">Verification</a>
</p>

---

## Latest release — v4.2.0 “Security & Integrity”

> **This is the first release that actually delivers what the previous README claimed.**
> The 4.1 line shipped a cleartext execution fallback, a non-secret “hardware key”, missing AAD
> binding, an APCu plaintext cache, a broken bridge namespace and contradictory license/version
> metadata. 4.2.0 removes those mechanisms instead of documenting around them.

| Area | What changed in 4.2.0 |
|---|---|
| **Cleartext execution** | The `plugin.php` fallback loader is gone. Nothing mounts or executes without an authenticated, schema-valid manifest. |
| **Installer race** | Uploads are validated and re-encrypted in a hidden staging directory; publication is a single atomic directory swap with rollback. |
| **Key hierarchy** | HKDF-SHA256 over WordPress salts + a 32-byte random pepper, with per-artifact domain separation. No more inode/CPU “hardware key”. |
| **AAD binding** | Every encryption and decryption is bound to `vgt:v4:<artifact-id>:<relative-path>`. Ciphertexts cannot be swapped inside an artifact. |
| **Shared hosting** | No plaintext in APCu. Optional APCu caching stores ciphertext only and invalidates by prefix. Vault defaults to outside the document root. |
| **Archive hardening** | Zip-slip (both slash directions), symlinks, compression bombs, entry/size/ratio limits and post-extraction tree verification. |
| **Console** | A rebuilt admin console in the VGT Glass 2100 material system: diagnostics, recovery, accessible confirmation dialog, no CDN assets, no emoji icons. |
| **Supply chain** | Optional Ed25519 manifest signatures, canonical JSON payload, and a policy switch to require signatures. |
| **Reference encryptor** | `bin/vgt-pack.php` with key generation, signing and classmap support, plus the normative [`ARTIFACT_SPEC.md`](ARTIFACT_SPEC.md). |

Full, itemised history: [`CHANGELOG.md`](CHANGELOG.md). Audit summary and reproducible test
ledger: [`docs/VERIFICATION.md`](docs/VERIFICATION.md).

### Download

* **Installable plugin ZIP:** [`vgt-omega-4.2.0.zip`](https://github.com/visiongaiatechnology/vgtos/releases/latest/download/vgt-omega-4.2.0.zip)
* **Checksums:** [`SHA256SUMS.txt`](https://github.com/visiongaiatechnology/vgtos/releases/latest/download/SHA256SUMS.txt)
* **Source:** this repository, tag [`v4.2.0`](https://github.com/visiongaiatechnology/vgtos/releases/tag/v4.2.0)

---

## Table of contents

- [Why VGT Omega](#why-vgt-omega)
- [How it works](#how-it-works)
- [Architecture](#architecture)
- [Security model](#security-model)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [The VGT Console](#the-vgt-console)
- [Verification and tests](#verification-and-tests)
- [Project structure](#project-structure)
- [Compatibility and limitations](#compatibility-and-limitations)
- [Development](#development)
- [Security reporting](#security-reporting)
- [Legal and license](#legal-and-license)

---

## Why VGT Omega

Commercial WordPress plugins face an uncomfortable trade-off: publish the source under GPL, or
avoid WordPress. VGT Omega separates the *runtime* from the *payload*:

| The old reality | VGT Omega 4.2 |
|---|---|
| Proprietary plugin logic has to sit in readable files | AES-256-GCM artifacts; the vault contains only ciphertext blobs |
| Encrypted loaders quietly fall back to `require` “for compatibility” | **No cleartext fallback exists.** Authenticated manifest or no execution |
| “Hardware-locked” keys derived from public machine data | HKDF-SHA256 from WordPress salts + a random pepper (+ optional strict node binding) |
| Upload → extract into the live vault → validate | Hidden staging → validate → re-encrypt → atomic publish |
| Decrypted code cached in shared APCu memory | Process-local RAM only; APCu at best caches ciphertext (opt-in) |
| Docs promise AAD/HKDF behaviour the code never implemented | AAD is mandatory on every crypto operation; the docs match the code |

The legal reasoning for loading independent artifacts through WordPress hook interfaces is
documented separately in [`legal.md`](legal.md). It is not legal advice.

---

## How it works

```
package (.zip) ──► Admin capability + nonce + rate limit
                     │
                     ▼
              hidden staging directory (0700)
                     │  archive validation: zip-slip, symlinks, bombs, limits
                     ▼
              cloud-key decrypt of the manifest (+ optional Ed25519 verify)
                     │  per-payload SHA-256 and size verification
                     ▼
              re-encrypt with server-derived per-artifact key + AAD
                     │
                     ▼
              atomic rename into the vault  ──►  process-local registry
                                                     │
                                      vgt:// stream wrapper (read-only)
                                                     │
                                  include_once "vgt://<id>/plugin.php"
```

* The **cloud key** protects the package in transit and is discarded after import.
* The **artifact key** is derived on the server and never persisted.
* The vault stores `vgt_manifest.vgt` plus randomly named `*.vgtblob` payloads — no source
  filenames, no `.php` files, no plaintext.

---

## Architecture

```
┌────────────────────────────────────────────────────────────────────┐
│ LAYER 0  mu-plugins/vgt-omega-kernel.php (auto-generated, atomic)  │
│          requires vgt-os.php before all regular plugins            │
├────────────────────────────────────────────────────────────────────┤
│ BOOTSTRAP   Plugin (composition root, load-order safe, fail-closed)│
│             PSR-4 autoloader (O(1) cache, realpath jail)           │
├────────────────────────────────────────────────────────────────────┤
│ BRIDGE      Contracts · Container · WordPress | Laravel | Standalone│
├────────────────────────────────────────────────────────────────────┤
│ VAULT       VaultManager facade                                    │
│  Storage    VaultLocator (out-of-webroot) · VaultLockdown          │
│             SafeArchive · ArtifactRepository · ArtifactInstaller   │
│  Crypto     CryptoEngine · KeyDerivation · Manifest · CanonicalJson│
│             SignatureVerifier (Ed25519)                            │
│  Runtime    ArtifactMounter · ManifestRegistry · StreamWrapper     │
│             ClassmapAutoloader · AssetResolver                     │
├────────────────────────────────────────────────────────────────────┤
│ ADMIN       ArtifactController (nonce, capability, rate limit)     │
│ UI          Glass 2100 security console (local CSS/JS, no CDN)     │
├────────────────────────────────────────────────────────────────────┤
│ ARTIFACT    encrypted blobs (.vgtblob) + encrypted manifest        │
│             vgt:// RAM decryption, never a filesystem plaintext    │
└────────────────────────────────────────────────────────────────────┘
```

---

## Security model

| Control | Implementation |
|---|---|
| Authenticated encryption | AES-256-GCM, random 96-bit IV, 128-bit tag, for payloads and manifests |
| Context binding | Mandatory AAD: `vgt:v4:<artifact-id>:<relative-path>` and `vgt:pack:*` during transport |
| Key hierarchy | HKDF-SHA256 over `AUTH_KEY` + `SECURE_AUTH_KEY` + `LOGGED_IN_KEY` + `NONCE_KEY` + 32-byte pepper |
| Transport | 32-byte cloud key; discarded after import; legacy containers accepted for import only |
| Import | Hidden staging, realpath jail, size/SHA-256 verification, raw-PHP rejection, atomic publish |
| Archive hardening | Zip-slip, symlink, bomb, entry/size/ratio limits, post-extraction tree audit |
| Execution | No cleartext fallback; locked manifests are never executed; read-only `vgt://` wrapper |
| Shared memory | No plaintext in APCu; opt-in ciphertext cache with prefix-scoped invalidation |
| Web exposure | Out-of-webroot vault, Apache 2.2/2.4 + IIS deny files, neutral `.vgtblob`, 96-bit CSPRNG ids |
| Supply chain | Optional Ed25519 manifest signatures; `VGT_OMEGA_REQUIRE_SIGNATURES` policy |
| Admin actions | Nonce + `activate_plugins` capability + per-user rate limit + opaque error references |
| Availability | Memory pre-flight, typed exceptions instead of OOM fatals, safe-mode break-glass |

### Hardening constants (`wp-config.php`)

```php
// Vault location (default: auto-detected outside the document root)
define('VGT_OMEGA_VAULT_DIR', '/home/account/.vgt-vault');

// Strict node binding for dedicated servers (default: soft, migration-safe)
define('VGT_OMEGA_BINDING_MODE', 'strict');

// Reject unsigned artifacts (recommended for production supply chains)
define('VGT_OMEGA_REQUIRE_SIGNATURES', true);

// Break-glass: pause all artifact mounting, keep the console usable
define('VGT_OMEGA_SAFE_MODE', true);

// Opt-in: cache only AES-GCM ciphertext in APCu (never plaintext)
define('VGT_OMEGA_APCU_BLOB_CACHE', true);
```

Threat model, closed findings, recovery procedures and residual risks:
[`SECURITY.md`](SECURITY.md).

---

## Requirements

* WordPress 6.0+
* PHP 8.1+ (verified on PHP 8.5 CLI)
* `ext-openssl` with AES-256-GCM (required)
* `ext-json` (required)
* `ext-zip` (preferred backend; WordPress PclZip is the fallback)
* `ext-sodium` (only for Ed25519-signed artifacts)

No Composer packages, no JavaScript framework, no external CDN at runtime.

---

## Installation

### Option A — Installable ZIP (recommended)

1. Download [`vgt-omega-4.2.0.zip`](https://github.com/visiongaiatechnology/vgtos/releases/latest/download/vgt-omega-4.2.0.zip).
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the ZIP, install, activate.
3. Open **VGT Omega** in the admin menu.

The ZIP extracts to `wp-content/plugins/vgt-omega/` and contains the runtime, the reference
encryptor CLI and the documentation. Tests are not shipped inside the install package.

### Option B — From source

```bash
git clone https://github.com/visiongaiatechnology/vgtos.git
cp -R vgtos /path/to/wp-content/plugins/vgt-omega
```

Activate **VGT Omega System** in *Plugins*. The plugin auto-deploys an atomic layer-0
mu-plugin kernel on admin requests. If `wp-content/mu-plugins` is not writable, it keeps
working as a regular plugin — the console reports `layer-0 = false`.

On first admin request the console shows layer-0 status, vault isolation, binding mode,
signature policy, RAM headroom and the crypto capability matrix.

---

## Quick start

### 1. Build an artifact

```bash
# Generate a one-time cloud key and an optional Ed25519 signing keypair
php bin/vgt-pack.php --keygen

# Package a plugin directory
php bin/vgt-pack.php \
  --source=./my-plugin \
  --out=./my-plugin.vgt.zip \
  --key=<64-hex cloud key> \
  --entry=plugin.php \
  --name="My Plugin" \
  --sign-key=<base64 Ed25519 secret> \
  --classmap=./classmap.json
```

`classmap.json` enables lazy decryption of classes:

```json
{
  "Vendor\\Plugin\\Kernel": "src/Kernel.php",
  "Vendor\\Plugin\\Admin": "src/Admin.php"
}
```

### 2. Deploy in the console

**VGT Omega → Deploy artifact**: select the ZIP, paste the cloud key, optionally choose an
existing artifact for a zero-downtime rotation, deploy. The console reports
`installed`/`rotated`; the vault registry shows the artifact as **SECURE RUNTIME ACTIVE**.

The cloud key is discarded after import. The artifact is now protected by the server-derived
key hierarchy.

### 3. Rotate or remove

* **Rotate:** deploy the new package with the same artifact selected as rotation target.
  The old artifact stays mounted until the new one is fully staged and validated.
* **Remove:** console → Vault → *Purge*. Artifact directory, metadata, public mirror and
  ciphertext cache entries are removed.
* **Break-glass:** set `define('VGT_OMEGA_SAFE_MODE', true);` to pause mounting while keeping
  rotation and deletion available.

The byte-level container and manifest format is documented in
[`ARTIFACT_SPEC.md`](ARTIFACT_SPEC.md).

---

## The VGT Console

The admin console follows the **VGT Glass 2100** material system: layered glass surfaces
(Z0–Z4), a product-specific mineral blue-green palette with OKLCH tokens and sRGB fallbacks,
a single light model, no background grids, no neon and no emoji icons. It is built for
security work, not for a hacker movie:

* **Vault stage** — artifact registry with explicit runtime verdicts, not colour-only states.
* **Deployment rail** — signed/unsigned package, rotation target, capability-gated actions.
* **Diagnostics strip** — layer-0 state, vault isolation, key-binding mode, signature policy,
  AES-GCM/Sodium/zip/PclZip/APCu availability, RAM headroom and boot-error state.
* **Recovery** — safe-mode instructions, locked-artifact causes and one-click purge.
* **Accessibility** — native `<dialog>` confirmation with focus trap/restore, visible focus,
  reduced-motion/transparency and forced-colors support, labelled controls, 44px+ targets.

All styles and scripts are local. Rendered HTML previews of the production render path and a
static accessibility/contrast audit live in [`docs/visual/`](docs/visual/) — real browser
screenshots are `NOT_TESTED` in the build environment and are not claimed.

---

## Verification and tests

```bash
php tests/run.php      # dependency-free suite: 71/71 tests, exit 0
php tests/lint.php     # php -l + 500-line budget: 97 files, exit 0
php tests/run.php Crypto
```

The suite uses real OpenSSL, libsodium, ZIP fixtures and isolated filesystem fixtures. It covers:

* AES-GCM round-trips, AAD mismatch, tampering, strict headers and legacy import;
* manifest schema attacks, raw-executable rejection, Ed25519 signature policy;
* path jail, zip-slip, symlink and compression-bomb archives;
* installer staging, no-plaintext-at-rest assertions, rotation, deletion, wrong-key cleanup;
* `vgt://` reads, traversal, directory listing, `url_stat`, classmap loading and tamper rejection;
* safe mode, layer-0 kernel linting/atomic deployment and a WordPress-shaped bootstrap;
* static gates: forbidden anti-patterns, legacy removal, identity, docs links, CDN scan, lint.

A summary of the full ledger, environment limitations and the independent adversarial re-audit
(no security defect found; classification **GOLD**) is in [`docs/VERIFICATION.md`](docs/VERIFICATION.md).

**Honest status:** code-level quality is **GOLD**, not PLATIN/DIAMANT. PLATIN requires verified
target-platform paths (real WordPress admin upload, PclZip, APCu, FPM memory limits, web-server
deny enforcement) which this build environment cannot provide. Those items are listed as
`NOT_TESTED`, not as passing.

---

## Project structure

```
vgt-omega/
├── vgt-os.php                    # plugin header + bootstrap entry
├── VERSION                       # central version source (4.2.0)
├── composer.json                 # PSR-4 "VGT\\Omega\\" => src/
├── README.md · CHANGELOG.md · SECURITY.md · ARTIFACT_SPEC.md · legal.md
├── assets/sirdu.png              # Sir Quacksec, VGT mascot
├── bin/vgt-pack.php              # reference encryptor CLI
├── docs/
│   ├── VERIFICATION.md           # test ledger, audit summary, limitations
│   └── visual/                   # rendered console previews + static design audits
├── src/
│   ├── Bootstrap/                # autoloader, composition root
│   ├── Bridge/                   # contracts, DI container, adapters, providers
│   ├── Core/                     # exception hierarchy, error reporter
│   ├── System/
│   │   ├── Deployment/           # layer-0 mu-plugin deployment
│   │   └── Vault/                # crypto, storage, runtime, admin facade
│   ├── Admin/ArtifactController.php
│   ├── Tooling/                  # reference packager
│   └── UI/Dashboard/            # Glass 2100 console (sections, components, assets)
├── tests/                        # dependency-free suite + fixture harness
└── .github/workflows/quality.yml # CI: lint + test + static gates
```

Every production PHP file stays within a 500-line budget; the structure is intentionally
modular (no god classes, no monolith).

---

## Compatibility and limitations

| Environment | Behaviour |
|---|---|
| Apache 2.2/2.4 | `.htaccess` denies the whole vault (dual syntax) |
| IIS | `web.config` authorization deny; `.vgtblob` is not mapped to PHP |
| Nginx/Caddy/LiteSpeed | Blobs are non-executable ciphertext with random names; add a server-level deny rule for the webroot fallback path |
| No `ext-zip` | WordPress PclZip backend with the same validation pipeline |
| No APCu | Fully supported; APCu is only an opt-in ciphertext cache |
| 64–128 MB PHP workers | Memory pre-flight aborts oversized operations with a typed error instead of OOM |

Known limitations:

* PHP's OPcache cannot cache files served through userland stream wrappers. Every request pays
  AES-GCM decryption and PHP compilation for the artifact files it includes. The classmap lazy
  loader and `VGT_RAW` smart compression reduce this to the files actually used; the limitation
  is architectural to PHP and is documented rather than hidden.
* Encrypted assets are not HTTP-servable by design. Public CSS/JS/images must be marked as
  `raw` public assets; they are mirrored to `uploads/vgt-public/<id>/…`.
* A compromised server or malicious administrator can read mounted plaintext from RAM. The
  vault protects data at rest and distribution, not an already-rooted host.
* Real WordPress end-to-end, PclZip, APCu, FPM memory-drill and web-server deny verification
  remain open staging-host tasks (see [`docs/VERIFICATION.md`](docs/VERIFICATION.md)).

---

## Development

```bash
php tests/lint.php          # syntax + line-budget gate
php tests/run.php           # full suite (no PHPUnit, no network)
php bin/vgt-pack.php --help # CLI reference
```

CI runs on every push and pull request via
[`.github/workflows/quality.yml`](.github/workflows/quality.yml) (PHP 8.3, OpenSSL, zip,
sodium). Contribution rules: [`CONTRIBUTING.md`](CONTRIBUTING.md).

No third-party PHP runtime dependencies are bundled. The only runtime components are PHP,
OpenSSL and WordPress itself.

---

## Security reporting

Please **do not** open a public issue for security reports. Send a private report to
`info@visiongaia.de` with affected version, reproduction and impact. Do not test against
systems you do not own or have written permission to test. Full policy:
[`SECURITY.md`](SECURITY.md).

---

## Legal and license

VGT Omega is licensed under the **GNU Affero General Public License v3.0 or later**
([`LICENSE`](LICENSE)). The interoperability analysis for loading independent artifacts through
WordPress hook interfaces is documented in [`legal.md`](legal.md) — it is not legal advice.

The runtime is free and open source. Commercial VGT products (such as the APEX encryptor with
polymorphic AST obfuscation) build on the same artifact contract and are licensed separately.

---

<p align="center">
  <img src="assets/sirdu.png" alt="Sir Quacksec" width="90"><br>
  <strong>Sir Quacksec approves this vault.</strong><br>
  Built by <a href="https://visiongaiatechnology.de">VisionGaia Technology</a> — evidence over claims.
</p>
