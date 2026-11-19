# VGT OMEGA — ARTIFACT SPECIFICATION

**Format:** `vgt-artifact/4.2`
**Runtime:** VGT Omega System 4.2.1
**Status:** normative. The reference implementation (`src/Tooling/ArtifactPacker.php`, `bin/vgt-pack.php`) and the importer (`src/System/Vault/Storage/ArtifactInstaller.php`) implement this document.
**License:** AGPL-3.0-or-later

This document defines the `.vgt` package format, the encrypted container layout, the manifest schema, the AAD contexts, the signature format and every hard limit enforced by the runtime. A third-party encryptor that follows this document produces packages that VGT Omega accepts.

---

## 1. Terminology and trust model

| Term | Meaning |
|---|---|
| **Cloud key** | 32 raw bytes (64 hex) that protect the package in transit. It is entered by the administrator during import and is never stored by VGT Omega. |
| **Artifact key** | Per-artifact AES-256 key derived on the server with HKDF-SHA256. Never leaves the server, never persisted. |
| **Vault** | Server-side directory that stores only encrypted manifests and blob payloads. |
| **Blob** | A single file below `artifacts/<artifact-id>/blobs/` with a random 32-hex name and the `.vgtblob` extension. |
| **Entry point** | The relative PHP file that is `include_once`d when the artifact is mounted. |

Trust boundary: the uploaded ZIP and its manifest are **untrusted input**. They are validated into a hidden staging directory, fully decrypted with the cloud key, re-encrypted with the server-derived artifact key, and only then published atomically. At no point does an uploaded PHP file become executable from the vault before validation.

The cloud key protects transport only. After import, the artifact is unreadable without the server-side key material (WordPress salts + random pepper + optional node binding).

---

## 2. Package layout (`.zip`)

```
artifact.zip
├── vgt_manifest.vgt                  # encrypted manifest (cloud-key container)
├── plugin.php                        # encrypted payload (cloud-key container)
├── src/Foo.php                       # encrypted payload
└── assets/app.css                    # optionally cleartext public asset ("raw")
```

* The ZIP is a normal ZIP archive (deflate). The importer also accepts the WordPress `PclZip` backend when `ext-zip` is unavailable.
* `vgt_manifest.vgt` **must** exist and must be a cloud-key encrypted container (§4) whose AAD is `vgt:pack:/vgt_manifest.vgt`.
* Every other entry is either an encrypted payload container or a cleartext *public asset*.
* The archive contains no directory metadata requirements; directories are implied by path names.
* VGT Omega never trusts archive metadata for path resolution: every name is validated and normalized before extraction (§7).

---

## 3. Manifest schema

The manifest plaintext is UTF-8 JSON. Unknown top-level fields are rejected.

```json
{
  "format": "vgt-artifact/4.2",
  "entry": "plugin.php",
  "name": "My Plugin",
  "created_at": "2026-11-19T12:00:00+00:00",
  "files": {
    "plugin.php": {
      "mode": "encrypted",
      "compression": "none",
      "size": 812,
      "sha256": "2f7c…64 lowercase hex…"
    },
    "src/Foo.php": {
      "mode": "encrypted",
      "compression": "gz",
      "size": 42133,
      "sha256": "9a1e…"
    },
    "assets/app.css": {
      "mode": "raw",
      "compression": "none",
      "size": 1200,
      "sha256": "b3c0…"
    }
  },
  "classmap": {
    "Vendor\\Plugin\\Foo": "src/Foo.php"
  },
  "signature": {
    "alg": "ed25519",
    "public_key": "<base64 32-byte Ed25519 public key>",
    "sig": "<base64 64-byte Ed25519 signature>",
    "kid": "release-2026-11"
  }
}
```

### 3.1 Field rules

| Field | Required | Rules |
|---|---|---|
| `format` | yes | Exactly `vgt-artifact/4.2`. Importer also accepts `vgt-artifact/4.0` / `vgt-artifact/4.1` **for cloud-key import only** (legacy upgrade path). |
| `entry` | yes | Normalized relative path (§7), must exist in `files`, must have `mode = encrypted`. |
| `name` | no | Display name, ≤ 120 characters, no control characters. |
| `created_at` | no | ISO-8601 string, ≤ 40 characters. |
| `files` | yes | Object with 1–1000 entries. Keys are normalized relative paths. |
| `classmap` | no | Object with at most 2000 `FQCN => relative-path` entries. Each target must be an **encrypted** payload. `FQCN` must match `^[A-Za-z_][A-Za-z0-9_]*(\\[A-Za-z_][A-Za-z0-9_]*)*$`. |
| `signature` | no | Ed25519 signature over the canonical manifest payload (§5). |

### 3.2 File entry rules

| Field | Required (4.2) | Rules |
|---|---|---|
| `mode` | yes | `encrypted` or `raw`. |
| `compression` | yes | `gz` (DEFLATE) or `none`. Informational during cloud import; the server re-selects compression with the smart rule (§6). |
| `size` | yes | Cleartext size in bytes, `0 … 8 MiB`. Sum over all files ≤ 32 MiB. |
| `sha256` | yes | Lowercase hex SHA-256 of the **cleartext** file. Verified after import; a mismatch aborts the installation. |
| `blob` | runtime only | 32-hex blob name. Added by the importer; never supplied by the packager. |

`raw` is only allowed for public-asset extensions (`css, js, mjs, map, png, jpg, jpeg, gif, webp, avif, ico, woff, woff2, ttf, otf, eot, mp4, webm, mp3, ogg, wav, txt, md, json, pdf`). Executable extensions (`php, phtml, phar, inc, sh, exe, …`) and SVG are rejected in `raw` mode. This prevents cleartext executable payloads and same-origin script execution through mirrored assets.

### 3.3 Installed manifest

After import, VGT Omega writes a new manifest (same schema) whose `files` entries carry the final `blob` name, the final compression flag and the verified size/hash. The installed manifest is encrypted with the artifact key and the AAD `vgt:v4:<artifact-id>:/vgt_manifest.vgt`. A transported signature is verified at import time but is **not** copied into the installed manifest (the payload is re-encrypted, so the old signature would no longer match); the metadata field `signed` records that the source package was signature-verified.

---

## 4. Encrypted container layout

All containers use AES-256-GCM.

```
offset  size  field
0       9     magic "VGT_GUARD"
9       1     format version 0x42
10      1     flags (bit 0 = gzip/DEFLATE applied; all other bits must be zero)
11      12    GCM IV (random per encryption)
23      16    GCM authentication tag
39      n     ciphertext
```

* The magic **must** be the literal prefix of the file. A container with a prefix, BOM, whitespace or trailing garbage before `VGT_GUARD` is rejected. A container with appended bytes after the ciphertext is rejected by GCM authentication.
* `gzdecode()` is always called with a hard `max_length` budget (8 MiB) — a decompression bomb cannot exhaust the worker.
* Legacy v4.0/v4.1 containers (`magic + IV + tag + gzip ciphertext`, no AAD, no version/flags byte) are accepted **only** during cloud-key import and are immediately re-encrypted into the v4.2 format. They are never mounted.

### 4.1 AAD contexts

AAD is mandatory and must be non-empty (≤ 512 bytes).

| Purpose | AAD string |
|---|---|
| Package payload file | `vgt:pack:<normalized-relative-path>` |
| Package manifest | `vgt:pack:/vgt_manifest.vgt` |
| Installed payload file | `vgt:v4:<artifact-id>:<normalized-relative-path>` |
| Installed manifest | `vgt:v4:<artifact-id>:/vgt_manifest.vgt` |

Because the artifact id and the relative path are bound into the GCM tag, ciphertexts cannot be swapped between files, between artifacts or between paths. AAD mismatch and tampering both surface as an authentication failure and the payload is rejected fail-closed.

---

## 5. Ed25519 signatures (optional)

The `signature` field signs the **canonical manifest payload**:

1. Take the manifest object.
2. Remove the `signature` member.
3. Recursively sort all object keys (lists keep their order).
4. Encode with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
5. Sign/verify those exact bytes with Ed25519 (`sodium_crypto_sign_detached` / `sodium_crypto_sign_verify_detached`).

The runtime verifies signatures when present. Sites that require signed artifacts set:

```php
define('VGT_OMEGA_REQUIRE_SIGNATURES', true);
```

in `wp-config.php`. Unsigned (or invalidly signed) packages are then rejected at import and at mount time. libsodium is part of PHP 8; when a signed manifest is present and libsodium is unavailable, the import fails closed with an availability error.

---

## 6. Smart compression (VGT_RAW / VGT_GZ)

The format distinguishes per-file compression:

* `VGT_RAW` — cleartext < 16 KiB: no DEFLATE. `gzdecode()` is skipped entirely at runtime, saving CPU and a second allocation.
* `VGT_GZ` — cleartext ≥ 16 KiB and DEFLATE actually shrinks the payload: gzip level 9.

The decision is made by the importer at re-encryption time and recorded in the installed manifest and the container flags (flag bit `0x01`). Packagers may pre-compress however they prefer; the container flags are authoritative for decoding. If gzip does not reduce the size, the payload is stored raw even above the threshold.

---

## 7. Path rules (hard limits)

Applies to ZIP entry names, manifest paths, classmap targets and `vgt://` stream URIs:

* Reject NUL bytes and control characters `0x00–0x1F`, `0x7F`.
* Reject absolute paths, leading `/` or `\`, Windows drive letters (`C:`), and any `:` (alternate data streams).
* Backslashes are treated as separators; `..` segments in either slash direction are rejected.
* Reject segments ending in space or `.` (Windows-hostile).
* Max path length 255 bytes, max segment length 100 bytes, max depth 20.
* Reject duplicate normalized paths and case-insensitive collisions.
* Reject symbolic links in the archive and in the extracted staging tree.
* Extraction must stay inside the staging directory (realpath jail); files not declared by the manifest are rejected.

Archive limits: max archive 16 MiB, max 1000 entries, max total uncompressed 32 MiB, max single file 8 MiB, max compression ratio 50:1. Violations abort the import before extraction.

---

## 8. Installation pipeline

```
upload (.zip) ─► capability + CSRF + rate limit
       │
       ▼
actual filesize on disk (not $_FILES['size'])
       │
       ▼
hidden staging /install_<32-hex>/incoming   (0700, outside mount scan)
       │  SafeArchive.inspect()  → limits, zip-slip, symlinks, bombs
       │  SafeArchive.extract()  → post-extraction verification
       ▼
decrypt vgt_manifest.vgt with cloud key (legacy allowed for import)
       │  Manifest schema validation, optional Ed25519 verify
       ▼
for each declared file:
   path jail → decrypt (legacy allowed) → verify size + SHA-256
   raw asset must be non-executable public-asset extension
   re-encrypt with per-artifact key + AAD (smart compression)
       ▼
write blobs (<blob-id>.vgtblob, 0600) + encrypted installed manifest
       ▼
atomic publish: existing target (rotation) → .trash, staging → artifacts/<id>
       │  rollback restores the previous artifact if publish fails
       ▼
mirror raw public assets to uploads/vgt-public/<id>/… (non-secret)
       ▼
metadata (name, updated, entry, files, signed, format) + APCu ciphertext invalidation
```

On any exception the staging directory is removed and no partial artifact remains. The previous artifact of a rotation stays untouched until the new one is fully staged.

---

## 9. Runtime mounting

1. The importer derives the artifact key: `HKDF-SHA256(master, info="vgt-artifact|<id>")`.
2. On boot, VGT Omega reads and authenticates the installed manifest, verifies the optional signature and requires every file entry to reference an installed blob.
3. A read-only `vgt://` stream wrapper is registered. `include_once "vgt://<id>/plugin.php"` decrypts the blob on demand, authenticates it with the bound AAD, and serves the plaintext from process-local RAM only.
4. Optional manifest `classmap` entries register lazy autoloaders, so only the entry bootstrap and the classes actually used in a request are decrypted and parsed.
5. `stat()` on `vgt://` returns the real cleartext size and the stable manifest mtime.
6. If a manifest cannot be authenticated (wrong salts, missing pepper, corruption), the artifact is marked *locked*, never executed. There is no cleartext fallback.

Plaintext is never written to disk and never stored in shared memory. An opt-in APCu cache stores **ciphertext only**, keyed `vgt_blob_<sha256>`, with prefix-scoped invalidation.

---

## 10. Key derivation

```
master = HKDF-SHA256(
    ikm  = pepper || AUTH_KEY || SECURE_AUTH_KEY || LOGGED_IN_KEY || NONCE_KEY
           || binding-mode || node-fingerprint,
    salt = empty,
    info = "vgt-omega-4.2-master",
    len  = 32
)
artifactKey(id) = HKDF-SHA256(master, info="vgt-artifact|<id>", len=32)
```

* `pepper`: 32 random bytes generated on first import and stored as a hex option; provisioning is race-safe (read-back after write). It is not a replacement for `wp-config.php` salts.
* **SOFT_BIND (default):** no node-specific fingerprint. Storage migrations, inode changes and domain moves do not brick the vault.
* **STRICT_HW_BIND:** additionally binds OS, CPU architecture, `ABSPATH` inode and DB identity. For dedicated servers that want clone resistance, at the cost of migration effort:

```php
define('VGT_OMEGA_BINDING_MODE', 'strict');
```

Changing salts, the pepper or the binding mode after artifacts have been installed makes existing artifacts unreadable (they appear as *locked*). This is a deliberate fail-closed property. Recovery: restore the original `wp-config.php` salts / database pepper, or re-import the artifact with its cloud key (rotation).

---

## 11. CLI reference encryptor

```bash
# Generate a cloud key and (optionally) an Ed25519 signing keypair
php bin/vgt-pack.php --keygen

# Package a plugin
php bin/vgt-pack.php \
  --source=./my-plugin \
  --out=./my-plugin.vgt.zip \
  --key=<64 hex> \
  --entry=plugin.php \
  --name="My Plugin" \
  --sign-key=<base64 Ed25519 secret> \
  --classmap=./classmap.json
```

`classmap.json` format:

```json
{
  "Vendor\\Plugin\\Kernel": "src/Kernel.php",
  "Vendor\\Plugin\\Admin": "src/Admin.php"
}
```

Exit codes: `0` success, `1` fatal, `2` input error, `3` security rejection, `4` storage error.

The packaging machine needs `ext-zip` and `ext-openssl`; signing needs `ext-sodium`. The runtime also accepts `PclZip` when `ext-zip` is unavailable.

---

## 12. Compatibility and migration

| Source | Import | Mount | Notes |
|---|---|---|---|
| `vgt-artifact/4.2` | yes | yes | Current format. |
| `vgt-artifact/4.0` / `4.1` cloud packages | yes (legacy decrypt, no AAD) | only after import | Re-encrypted into 4.2 with AAD binding. |
| Legacy installed artifacts (v4.0/4.1, no AAD, legacy hardware key) | no | no | Not compatible with the v4.2 key hierarchy. Re-package and re-import (rotation) is required. |
| Unsigned packages | yes (unless signatures required) | yes | Enable `VGT_OMEGA_REQUIRE_SIGNATURES` for production supply-chain hardening. |

---

## 13. Error behaviour

* Invalid package, path, schema, signature, size or hash mismatch → typed `SecurityException`; client sees an opaque message plus a `VGT-ERR-XXXXXXXX` reference; details go to `error_log`.
* Missing capability, `ext-zip`/`PclZip`, libsodium when required, memory headroom, unwritable vault → `AvailabilityException`, operation aborted safely.
* Wrong cloud key format → `ValidationException` with a user-safe message.
* Any failure during import leaves the vault unchanged.

---

*This specification is normative for VGT Omega 4.2.1. The reference encryptor is intentionally minimal: the commercial VGT APEX encryptor adds polymorphic AST obfuscation on top of the same container contract.*
