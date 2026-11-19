# Contributing to VGT Omega

Thanks for helping improve VGT Omega. This project handles executable code and cryptography, so
contributions are held to a strict bar.

## Ground rules

* **Security reports:** do not open a public issue. See [`SECURITY.md`](SECURITY.md).
* **No secrets:** never commit keys, tokens, passwords, `.env` files, customer data or private
  hostnames. Test values must be recognisably synthetic.
* **No plaintext payload:** the runtime must never persist or execute an unauthenticated
  cleartext artifact. A “compatibility fallback” that loads raw code is an automatic rejection.
* **Fail closed:** new error paths must not degrade authentication, AAD binding, signature
  policy or path jails into warnings.
* **No dependencies without need:** the runtime is dependency-free by design. Adding Composer or
  JavaScript libraries requires a strong justification and a license review.

## Code standards

* PHP 8.1+, `declare(strict_types=1);`, SPDX header `AGPL-3.0-or-later` in every file.
* Typed exceptions: `ValidationException` (safe client message), `SecurityException`,
  `StorageException`, `AvailabilityException` (opaque client message, details to `error_log`).
* No `uniqid()`, no `rand()`, no `eval()`, no shell execution with untrusted input, no
  `unserialize()` on external data.
* Every filesystem path from an untrusted source goes through `PathGuard` and a realpath jail.
* Every crypto operation carries AAD; never weaken the container parser.
* Every PHP file stays at or below 500 lines; split by responsibility instead of growing files.
* Escape all dynamic HTML output (`esc_html`, `esc_attr`, `esc_url`) and build DOM with
  `textContent`, never with untrusted `innerHTML`.

## Before opening a pull request

Run the complete local gates:

```bash
php tests/lint.php   # php -l on all PHP files + 500-line budget, must exit 0
php tests/run.php    # full dependency-free suite, must exit 0
```

CI (`.github/workflows/quality.yml`) runs the same gates on PHP 8.3 for every push and pull
request. If you change behaviour, add or update a test that fails without your fix.

## Commit and release hygiene

* Keep commits focused; do not mix unrelated refactors with security changes.
* Never commit generated vaults, `tests/tmp/`, ZIP packages, logs or credentials.
* Version changes must update the plugin header, `Plugin::VERSION`, `README.md`, `CHANGELOG.md`
  and the release tag consistently.

## License

By contributing you agree that your contribution is licensed under
AGPL-3.0-or-later, matching [`LICENSE`](LICENSE).
