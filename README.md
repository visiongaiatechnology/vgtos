# ⚙️ VGT OS — Encrypted Artifact Vault & Bridge Infrastructure

[![License](https://img.shields.io/badge/License-AGPLv3-green?style=for-the-badge)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.0+-blue?style=for-the-badge&logo=php)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-6.0+-21759B?style=for-the-badge&logo=wordpress)](https://wordpress.org)
[![Status](https://img.shields.io/badge/Status-DIAMANT-purple?style=for-the-badge)](#)
[![Version](https://img.shields.io/badge/Version-4.0.0-orange?style=for-the-badge)](#)
[![VGT](https://img.shields.io/badge/VGT-VisionGaia_Technology-red?style=for-the-badge)](https://visiongaiatechnology.de)
[![Donate](https://img.shields.io/badge/Donate-PayPal-00457C?style=for-the-badge&logo=paypal)](https://www.paypal.com/paypalme/dergoldenelotus)

> *"WordPress Hooks are APIs. APIs are interfaces. Interfaces do not trigger the GPL."*

**VGT OS** is the open-source runtime kernel that allows any PHP developer to deploy encrypted, proprietary plugins on WordPress — legally, architecturally, and permanently.

Built on a legal opinion under German and European law: using `add_action()` and `add_filter()` constitutes API usage. APIs are interfaces. Interfaces do not trigger the GPL copyleft clause. **Your business logic is yours.**

[EU LEGAL (https://eur-lex.europa.eu/legal-content/DE/TXT/PDF/?uri=CELEX:32009L0024)]

---

## 🔑 Before You Start — The Encryptor

VGT OS is the **runtime**. It loads, decrypts, and executes encrypted artifacts in RAM.

To use VGT OS, you need an **encryptor** — a tool that compiles your plugin into an encrypted artifact (`.zip` + `vgt_manifest.vgt`) compatible with the VGT OS format.

**You have two options:**

### Option A — Build Your Own Encryptor
VGT OS ships with a documented artifact interface specification. Any developer can build a compatible encryptor that produces AES-256-GCM encrypted artifacts with a valid manifest.

The required artifact format is documented in [`ARTIFACT_SPEC.md`](ARTIFACT_SPEC.md).

### Option B — License the VGT APEX Encryptor
The **VGT APEX Encryptor** is VisionGaia Technology's proprietary compiler. It produces artifacts with:
- Polymorphic obfuscation (variable scrambling, string fragmentation)
- AST-level code transformation
- AES-256-GCM + HKDF-SHA256 + AAD Binding
- Automated manifest generation

[![Contact for Licensing](https://img.shields.io/badge/APEX_Encryptor-Request_License-10b981?style=for-the-badge)](https://visiongaiatechnology.de)

> VGT OS is free and open. The APEX Encryptor is available under a commercial license.

---

## 🚨 The Problem VGT OS Solves

For decades, commercial WordPress plugin developers have faced an impossible choice: publish your source code under GPL — or don't use WordPress at all.

| The Old Reality | VGT OS |
|---|---|
| ❌ WordPress Hooks = GPL infection of all logic | ✅ Hooks are APIs — EU/DE law confirmed |
| ❌ Closed source plugins = legal gray zone | ✅ Encrypted artifacts = legally sovereign |
| ❌ Source exposed on every server | ✅ AES-256-GCM — zero plaintext on disk |
| ❌ Reverse engineering trivial | ✅ Memory Stream — code lives only in RAM |
| ❌ No standard for plugin IP protection | ✅ Open runtime — your encryptor stays private |

---

## ⚖️ Legal Foundation

VGT OS is built on a formal legal position under **German and European law**, verified by legal counsel:

WordPress functions like `add_action()`, `add_filter()`, and `wp_enqueue_script()` function as **API interfaces** — not as copyleft-triggering derivative works.

Under EU software directive and German copyright law (UrhG), the use of an API does not constitute a derivative work. Your plugin logic, encrypted and loaded via VGT OS, remains your intellectual property.

> **This is not legal advice.** VGT OS provides the technical infrastructure. Consult your own legal counsel for your specific situation.

---

## 🏛️ Architecture — CORE 4.0

```
┌─────────────────────────────────────────────────────┐
│              MU-PLUGIN LAYER (ROOT LEVEL)            │
│  MuDeployer → loads before all plugins              │
│  Priority 1 on muplugins_loaded                     │
├─────────────────────────────────────────────────────┤
│              VGT OS KERNEL                           │
│  ┌──────────────┐  ┌────────────┐  ┌─────────────┐ │
│  │ PSR-4        │  │ IoC        │  │ Bridge      │ │
│  │ Autoloader   │  │ Container  │  │ Interface   │ │
│  │ O(1) Cached  │  │ Zero-      │  │ WordPress   │ │
│  │              │  │ Globals    │  │ Adapter     │ │
│  └──────────────┘  └────────────┘  └─────────────┘ │
├─────────────────────────────────────────────────────┤
│              VAULT MANAGER                           │
│  AES-256-GCM │ HKDF-SHA256 │ AAD Binding           │
│  Key stored encrypted in DB — never plaintext       │
├─────────────────────────────────────────────────────┤
│              VIRTUAL FILE SYSTEM                     │
│  vgt:// Stream Wrapper                              │
│  include_once "vgt://artifact_id/plugin.php"        │
│  Decryption → RAM only → zero disk footprint        │
├─────────────────────────────────────────────────────┤
│              YOUR ENCRYPTED ARTIFACT                 │
│  Compiled by your own encryptor (not included)      │
│  Loaded, decrypted, executed — never written to FS  │
└─────────────────────────────────────────────────────┘
```

---

## 🔐 Cryptographic Kernel

### AES-256-GCM Encryption
Every artifact is encrypted with AES-256-GCM — simultaneous authentication and encryption. No integrity manipulation goes undetected.

### HKDF-SHA256 Key Derivation
The master key is derived from WordPress's own `AUTH_KEY` via HKDF — never stored directly. Even with full database access, an attacker cannot reconstruct the key without the server environment.

### AAD Context Binding
Every decryption key is cryptographically bound to its artifact ID:

```
WITHOUT AAD: copy key → swap artifact → works ✓ (attack succeeds)
WITH AAD:    copy key → swap artifact → GCM tag mismatch → FAIL ✗
```

Key-swapping attacks are mathematically impossible.

### Memory-Only Execution
```
Artifact on disk            →  AES-256-GCM ciphertext (worthless without key)
Artifact in RAM             →  Decrypted, executed, garbage collected
Artifact on disk after run  →  Still ciphertext. Always.
```

Zero plaintext ever touches the filesystem.

---

## 🌐 Virtual File System — vgt:// Protocol

VGT OS registers a custom PHP stream wrapper that intercepts all file operations on the `vgt://` protocol:

```php
// How VGT OS loads your artifact internally:
include_once "vgt://vgt_69b5bfb70f34f/plugin.php";

// PHP calls StreamWrapper::stream_open()
// → Locates physical encrypted file
// → Decrypts via AES-256-GCM directly into RAM buffer
// → Returns decrypted content to PHP interpreter
// → No write operation. No temp file. No disk trace.
```

Path traversal attacks are blocked at stream level via `realpath()` validation against the artifact root.

---

## 🛡️ Security Hardening

| Layer | Mechanism |
|---|---|
| **Input Sanitization** | Recursive deep sanitization on all GET/POST with 50-level nesting limit |
| **Control Char Strip** | All ASCII 0–31 and 127 stripped from every input |
| **Object Injection** | Container blocks PHP Object Injection via `__wakeup()` exception |
| **Path Traversal** | `realpath()` + root boundary check on every stream operation |
| **Key Validation** | Exactly 64 HEX characters required — deterministic `mb_strlen` validation |
| **CSRF Protection** | `wp_verify_nonce` on all admin POST actions |
| **Capability Check** | `manage_options` required for all vault operations |

---

## 🖥️ VGT OS Dashboard

```
VGT OS  [CORE 4.0]                         SYSTEM ONLINE
Encrypted Artifact Vault & Bridge           MEMORY STREAM ACTIVE
──────────────────────────────────────────────────────────────
  1                    1                AES-GCM
  MOUNTED ARTIFACTS    DECRYPTED        CIPHER PROTOCOL
                       KERNELS
──────────────────────────────────────────────────────────────
DEPLOY ARTIFACT               ARTIFACT VAULT
  Artifact Package (.zip)     vgt_69b5bfb70f34f
  Decryption Key (hex)          ● SECURE RUNTIME     [PURGE]
  [INITIALIZE DEPLOYMENT]
```

Ghost Injection renders encrypted artifacts as native WordPress plugins — complete with status indicators, vault management links, and visual runtime confirmation.

---

## 🚀 Installation

### Requirements
- WordPress 6.0+
- PHP 8.0+
- OpenSSL enabled
- Direct filesystem write access

### Setup

1. Download and extract to `/wp-content/plugins/vgt-os/`
2. Activate via **Plugins → Installed Plugins**
3. VGT OS auto-deploys to `mu-plugins/` — active on Layer 0 before all other plugins
4. Navigate to **VGT Console** to deploy your first artifact

### Deploying an Artifact

```
1. Encrypt your plugin with your encryptor of choice
2. Package as .zip with vgt_manifest.vgt included
3. VGT Console → Deploy Artifact → Upload .zip + Decryption Key
4. ● SECURE RUNTIME confirms successful memory-mount
```

---

## 📁 File Structure

```
vgt-os/
├── src/
│   ├── Adapters/
│   │   └── WordPressAdapter.php      ← Bridge implementation
│   ├── Contracts/
│   │   ├── BridgeInterface.php       ← Core contract
│   │   └── EnvironmentInterface.php  ← Environment isolation
│   ├── Core/
│   │   ├── Container.php             ← IoC DI Container
│   │   └── MuDeployer.php            ← Atomic MU-deployment
│   ├── System/
│   │   ├── VaultManager.php          ← Crypto kernel + mounting
│   │   └── StreamWrapper.php         ← vgt:// VFS implementation
│   └── UI/
│       └── Dashboard.php             ← Admin console
└── vgt-os.php                        ← Bootstrap
```

---

## 🔒 What Is NOT Included

VGT OS is the **runtime** — the vault that loads and executes encrypted artifacts.

The **encryptor** — the tool that compiles and encrypts your plugin into an artifact — is **not included** and remains proprietary to VisionGaia Technology.

You can use any AES-256-GCM compatible encryptor that produces the expected artifact format, or contact us for licensing the VGT OMEGA Encryptor.

---

## 🤝 Contributing

Pull requests are welcome. For major changes, please open an issue first.

**Contribution requirements:**
- GPG-signed commits strongly recommended
- No external analytics or tracking code
- Unit tests for new logic
- Security vulnerabilities: contact `security@visiongaiatechnology.de` — do NOT open a public issue

Licensed under **GNU AGPLv3** — if you run VGT OS as a hosted service, your modifications must be shared. The copyleft stops at the vault door. What's inside remains yours.

---

## ☕ Support the Project

VGT OS is free and open source. If it protects your business logic:

[![Donate via PayPal](https://img.shields.io/badge/Donate-PayPal-00457C?style=for-the-badge&logo=paypal)](https://www.paypal.com/paypalme/dergoldenelotus)

---

## 🏢 Built by VisionGaia Technology

[![VGT](https://img.shields.io/badge/VGT-VisionGaia_Technology-red?style=for-the-badge)](https://visiongaiatechnology.de)

VisionGaia Technology builds enterprise-grade security and AI tooling — engineered to the DIAMANT VGT SUPREME standard.

> *"The copyleft stops at the vault door. What's inside remains yours."*

---

*Version 4.0.0 (PLATIN STATUS) — VGT OS // Encrypted Artifact Vault & Bridge Infrastructure*
