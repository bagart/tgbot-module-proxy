# T12 — ImportProxiesCommand: parser → Eloquent application service

Plan ref: plan §§10.12 п.23, 11.10, 11.15 п.2–3, 11.28, 11.35 п.3/15/17;
INV-006/007.
Depends on: T02 (tenancy), T03 (ProxyEndpoint), T04 (ProxyCredential),
T09 (CredentialEncryptor), T10 (parser grammar), T11 (RawFeedEntry).

## Goal

Shared application command that imports proxy text into the inventory.
Consumed by bot `/import`, Import Wizard, API, CLI, and feeds — one code
path for all entry points (plan §11.10, §11.28). Orchestrates: parse →
RawFeedEntry staging → dedup → create ProxyEndpoint + ProxyCredential
(via Eloquent within TenantContext) → create ProxyAccess → seal credentials
via CredentialEncryptor. Returns structured result with created/skipped/error
counts.

## Deliverables (file-by-file)

### DTOs

- `src/Domain/Parsing/ImportProxiesCommand.php`
  ```php
  final readonly class ImportProxiesCommand implements JsonSerializable
  {
      public const int SCHEMA_VERSION = 1;

      public function __construct(
          public readonly string $text,           // raw proxy list text
          public readonly ?string $sourceLabel,  // optional label for ProxySource
          public readonly int $tenantId,         // from TenantContext; explicit for clarity
          public readonly ?string $idempotencyKey, // caller-provided dedup key
      ) {}
  }
  ```

- `src/Domain/Parsing/ImportResult.php`
  ```php
  final readonly class ImportResult implements JsonSerializable
  {
      public const int SCHEMA_VERSION = 1;

      public function __construct(
          public readonly int $totalLines,
          public readonly int $created,      // new ProxyEndpoint+Access created
          public readonly int $skipped,      // duplicate (already exists by identity)
          public readonly int $parseErrors,  // lines that failed parsing
          public readonly int $staged,       // RawFeedEntry rows written
          /** @var ImportResultError[] */
          public readonly array $errors,     // first N parse errors for response
          public readonly string $importBatchId, // UUID for this import run
      ) {}
  }
  ```

- `src/Domain/Parsing/ImportResultError.php`
  ```php
  final readonly class ImportResultError implements JsonSerializable
  {
      public function __construct(
          public readonly int $line,
          public readonly string $code,      // ParseErrorCode::value
          public readonly ?string $detail,
      ) {}
  }
  ```

### Application Service

- `src/Parser/ImportProxiesService.php`
  ```php
  final class ImportProxiesService
  {
      public function __construct(
          private readonly ProxyListParser $parser,
          private readonly CredentialEncryptor $encryptor,
          private readonly TenantContext $tenant,
      ) {}

      public function execute(ImportProxiesCommand $command): ImportResult { ... }
  }
  ```

  **Orchestration flow** (plan §11.28 pipeline):
  1. Validate `TenantContext` is set (throws `TenantNotResolvedException`).
  2. Compute `batch_hash = hash('sha256', trim($command->text))`.
  3. **Idempotency check** (plan §11.19): if `$command->idempotencyKey` is
     provided, check if an import with that key already completed for this
     tenant → return cached `ImportResult` if so. (For MVP: in-memory or
     DB-based dedup; full `api_idempotency` table is Stage 10.)
  4. Generate `importBatchId` (UUID).
  5. **Parse**: call `$this->parser->parse($command->text)` → `ParseResult`.
  6. **Stage**: for each line, create `RawFeedEntry` with status Pending/Parsed/Error.
     - Batch insert for performance (chunks of 500).
     - `batch_hash` = per-line hash for idempotency (plan §11.37 R6.8):
       `hash('sha256', $tenantId . "\x00" . $batchHash . "\x00" . $lineNumber)`.
  7. **For each `ParsedEntry`** (successful parses):
     a. **Canonicalize**: call `EndpointCanonicalizer::canonicalize()` to get
        `EndpointIdentity`.
     b. **Dedup endpoint**: `ProxyEndpoint::query()
        ->where('tenant_id', $tenantId)
        ->where('endpoint_identity_hash', ProxyEndpoint::identityHash($identity))
        ->first()` — if exists, skip (mark RawFeedEntry as Skipped).
     c. **Create endpoint**: `ProxyEndpoint::fromIdentity($identity, $originalHost)`.
     d. **Create credential** (if credential material present):
        - Build `ProxyCredential` with plaintext `secret` attribute (the model's
          `booted()` callback calls `CredentialEncryptor::encrypt()` — T09 wiring).
        - The encryptor seals the secret into `secret_envelope` and discards
          plaintext. Parser never encrypts (INV-007); the model callback does.
     e. **Create access**: `ProxyAccess::create([...])` linking endpoint + credential.
        - Access starts in `AccessState::New` with `TestabilityStatus::Testable`.
     f. Mark RawFeedEntry as Parsed, set `endpoint_id`.
  8. **For parse errors**: mark RawFeedEntry as Error, store `parse_error_json`.
  9. Return `ImportResult` with counts and first 20 errors.

  **Private IP/CIDR handling** (plan §11.35 п.15):
  The parser accepts `10.0.0.1:8080` as valid (inventory entry). The import
  service creates the endpoint normally. SSRF eligibility is a checker concern,
  not an import concern. No special handling needed — `EndpointCanonicalizer`
  validates the IP format.

  **MTProto path** (plan §§10.12 п.22, 11.35 п.12):
  When `ParsedEntry.credentialKind === CredentialKind::MtprotoSecret`:
  - `ProxyCredential` is created with `kind = MtprotoSecret`, `username = null`,
    `secret` = the hex/FakeTLS secret string.
  - `ProxyAccess` links endpoint + credential normally.
  - MTProto handshake checking happens in Stage 8, not here.

### Wiring

- Register `ImportProxiesService` as singleton in `ProxyOperationsServiceProvider`
  (alongside T09's `CredentialEncryptor` singleton).
- `ProxyListParser` registered as singleton (stateless, reusable).

## Conventions

- `ImportProxiesCommand` is the DTO (plan §11.15 п.2: "MVP uses internal
  application service"); it is NOT an HTTP endpoint — that's P2.
- `ImportResult` is serializable for bot responses / API responses.
- Credentials are sealed inside the `ProxyCredential::booted()` callback
  (T09 pattern) — `ImportProxiesService` does not call `CredentialEncryptor`
  directly for field encryption; it only ensures the model is created with
  the plaintext `secret` attribute.
- All Eloquent operations run within `TenantContext` scope (auto-scoped by
  `BelongsToTenant` trait).
- No secrets in logs/exceptions. Error messages reference line numbers and
  error codes, never credential content.

## Tests

### `tests/Feature/Parser/ImportProxiesServiceTest.php`

**Happy path**:
- Import `socks5://user:pass@1.2.3.4:1080\n1.2.3.5:1080` → 2 entries created,
  `created = 2`, RawFeedEntry rows with status Parsed.
- Imported endpoints exist in DB with correct protocol/host/port.
- Credential sealed: `secret_envelope` is non-null, `secret` attribute is null
  after save (T09 guarantee, verified here end-to-end).
- Access created with `state = New`.

**Dedup**:
- Import same text twice → second run `created = 0`, `skipped = 2`.
- Import text with duplicate lines within → each line creates one entry,
  second identical line within same batch is deduped by endpoint identity.

**Negative tenancy**:
- Call without TenantContext → `TenantNotResolvedException`.
- Import with TenantContext for tenant A, verify tenant B sees nothing.

**Parse errors**:
- Mixed valid + invalid lines → valid lines created, errors returned with
  line numbers, `parseErrors > 0`.
- `vless://...` line → `NonVpnRejected` error in result.

**MTProto**:
- `mtproto://abc123@1.2.3.4:443` → credential created with
  `kind = MtprotoSecret`, `username = null`.

**Idempotency**:
- Same `idempotencyKey` twice → second call returns same result without
  re-processing.

**RawFeedEntry staging**:
- All lines (valid, invalid, skipped) have corresponding RawFeedEntry rows.
- `batch_hash` is consistent for same input text.

### `tests/Unit/Parser/ImportResultTest.php`
- `ImportResult::jsonSerialize()` roundtrip via `fromJsonV1()`.

## Verify

```
timeout 120 ../../../vendor/bin/pest --testsuite Feature
timeout 120 ../../../vendor/bin/pest
```

## Out-of-scope

- Bot command wiring, API endpoints, Import Wizard UI (Stage 10–11).
- Feed sync system (Stage 9+).
- Verification / audit after import (Stage 4–5).
- Export / tg:// URI generation (Stage 9+).
