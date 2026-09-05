# T04 — ProxyCredential: protocol-specific credential storage (migration + model + factory)

Plan ref: §7 rows #3, #5; plan §§11.2–11.3, 11.21 (`proxy_credentials`),
§11.35 п.3. Depends on: T03.

## Goal

Store credentials as a separate entity with protocol-specific kinds, masked
representation and a deterministic `CredentialFingerprint` — plaintext secrets
never persisted unencrypted (ciphertext envelope lands in T08; this task defines
the columns so T08 only fills them).

## Deliverables (file-by-file)

- `src/Domain/Identity/CredentialKind.php` — backed enum:
  `BasicAuth`, `SocksAuth`, `MtprotoSecret` (plan §11.3). TitleCase keys,
  string values.
- `database/migrations/2026_08_27_000002_create_proxy_credentials_table.php`
  columns:
  - `id` uuid PK;
  - `tenant_id` foreignId constrained, NOT NULL;
  - `endpoint_id` foreignId nullable → `proxy_endpoints` (a credential profile
    is usually endpoint-bound; null allowed until linked);
  - `kind` string NOT NULL (`CredentialKind` value);
  - `username` string nullable;
  - `secret_envelope` json nullable — `{key_version, algorithm, nonce,
    ciphertext, tag}` written by T08 encryptor; NULL in this task's tests
    (plaintext column MUST NOT exist);
  - `fingerprint` string(64) NOT NULL — hex of
    `Domain\Identity\CredentialFingerprint` (HMAC-SHA256 over canonical payload;
    tenant_id deliberately NOT part of it — same creds share fingerprint across
    workspaces for the shared cache, §11.37 R6.2);
  - `masked_representation` string NOT NULL — e.g. `so***:***@1.2.3.4:1080`
    style mask; never contains the secret;
  - `timestamps`;
  indexes: INDEX `(tenant_id, endpoint_id)`; INDEX `(fingerprint)`;
  UNIQUE `(tenant_id, endpoint_id, kind, username)` partial-safe guard against
  duplicate profiles (adjust to SQLite-compatible plain unique where needed).
- `src/Models/ProxyCredential.php` — `HasFactory`, `HasUuids`, `BelongsToTenant`;
  `$hidden = ['secret_envelope']` (never serializes by default); maps to
  `Domain\Identity\CredentialFingerprint`; helper
  `mask(): string` returning the masked representation. Relationships:
  `endpoint()` belongsTo, `accesses()` hasMany (T05).
- `database/factories/ProxyCredentialFactory.php` — states `socksAuth()`,
  `basicAuth()`, `mtprotoSecret()`; definition computes a real
  `CredentialFingerprint` from fixture values.

## Conventions

- Secrets never logged / never in exception messages; `$hidden` guards accidental
  serialization. No plaintext password/secret column may appear anywhere.
- The model does not encrypt anything itself — encryption is T08's service
  (parser never encrypts, INV-007; application layer encrypts after parser).

## Tests

`tests/Feature/Models/ProxyCredentialTest.php`:
- factory roundtrip for all three kinds; fingerprint equals
  `CredentialFingerprint::for(...)` output (same value in two tenants — shared-cache rule);
- JSON/array serialization hides `secret_envelope` (negative leak case);
- cross-tenant invisibility + no-context throw (negative tenancy cases);
- duplicate `(tenant_id, endpoint_id, kind, username)` rejected (negative case).

## Verify

```
timeout 120 ../../../vendor/bin/pest --testsuite Feature
timeout 120 ../../../vendor/bin/pest
```

## Out-of-scope

- Envelope encryption implementation + DEK table (T08).
- Sealed runtime delivery to worker (`Wire\SealedCredentialPayload`, already Stage 0).
