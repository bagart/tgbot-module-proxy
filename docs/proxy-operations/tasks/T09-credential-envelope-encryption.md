# T09 — Credential envelope encryption: KEK/DEK, CredentialEncryptor, rotation

Plan ref: §7 row #7; plan §§10.12 п.13, 11.23, 11.35 п.3; INV-004/007.
Depends on: T04 (credential columns), T01 (config file).

> **Status note:** not blocked — plan §10.12 п.13 fixes KEK source
> (`PROXY_ENC_KEY` env, fallback derivation from `APP_KEY`) and §11.23 fixes
> the ciphertext format and rotation semantics. Two minor assumptions are
> flagged in README as OD-3 (DEK storage table + algorithm) — implement with
> the defaults below unless the owner overrides.

## Goal

Envelope encryption for credential secrets: KEK wraps a per-workspace DEK;
fields carry `{key_version, algorithm, nonce, ciphertext, tag}`. The encryptor
is application-layer only: parser never encrypts (INV-007), worker never sees
KEK/DEK or the encryptor service (INV-004).

## Deliverables (file-by-file)

- `database/migrations/2026_08_27_000009_create_proxy_workspace_deks_table.php`:
  - `id` uuid PK; `tenant_id` foreignId constrained NOT NULL UNIQUE;
  - `wrapped_dek` json NOT NULL — `{key_version, algorithm, nonce, ciphertext, tag}`
    (DEK wrapped by KEK);
  - `key_version` string NOT NULL (KEK version used for wrapping; enables lazy
    read migration on KEK rotation);
  - `created_at`, `rotated_at` nullable.
- `src/Encryption/CredentialEncryptor.php` — final class, registered as
  singleton in `ProxyOperationsServiceProvider`. API:
  - `encrypt(int $tenantId, string $plaintext): EncryptedField` (ensures DEK row,
    creates+wraps it on first use);
  - `decrypt(int $tenantId, EncryptedField $field): string`
    (unwraps DEK with current-or-historical KEK by `key_version`);
  - `rewrapDek(int $tenantId): void` (KEK rotation: unwrap old → wrap new;
    field values untouched — §11.23).
  Plaintext exists only inside method scope; never logged/exception-ed.
- `src/Encryption/EncryptedField.php` — `final readonly`, JsonSerializable,
  `SCHEMA_VERSION` const + `fromJsonV1()` per DTO style; serializes to exactly
  `{key_version, algorithm, nonce, ciphertext, tag}`.
- `src/Encryption/KekProvider.php` — resolves KEK once from
  `config('proxy-operations.encryption')` (`PROXY_ENC_KEY`, fallback KDF from
  `APP_KEY`); throws clear exception when neither is usable in production env.
- Wiring: singleton registrations in `ProxyOperationsServiceProvider`.
- Model touchpoint: `ProxyCredential` gains no logic; encryption calls happen in
  future application services (Stage 2 import pipeline), NOT in the model.

## Conventions

- AES-256-GCM (`aes-256-gcm`) via openssl; nonce 12 bytes random per operation.
- `key_version` = monotonic KEK version string from config
  (`encryption.key_version` default `k1`).
- No secret material may appear in exceptions, logs, or serialized payloads.

## Tests

`tests/Unit/Encryption/CredentialEncryptorTest.php` (pure, no DB where possible)
and `tests/Feature/Encryption/CredentialPersistenceTest.php`:
- roundtrip encrypt→decrypt equal; different tenants produce different DEKs
  (cross-tenant ciphertext unreadable — negative case);
- tampered tag/nonce/ciphertext fails decryption (negative case);
- `rewrapDek()` keeps old fields decryptable after KEK version bump
  (historical key_version path);
- EncryptedField JSON schema exact keys; `fromJson` rejects unknown version;
- config without any KEK source throws in prod-shaped env (negative case);
- arch check: nothing under `src/Wire/`, `src/Tool/`, worker-facing contracts
  references `CredentialEncryptor`/`KekProvider` (INV-004 guard, extend
  existing InvariantsTest).

## Verify

```
timeout 120 ../../../vendor/bin/pest --testsuite Feature
timeout 120 ../../../vendor/bin/pest
```

## Out-of-scope

- Export-with-credentials action & audit trail (#7 export part, Stage 10).
- Worker sealed runtime payload unseal service (Stage 4/5, contract already in T90).
- Multi-KMS/HSM backends.
