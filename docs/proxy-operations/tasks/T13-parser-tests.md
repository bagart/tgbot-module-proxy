# T13 — Parser + import integration tests

Plan ref: plan §§10.12 п.3/22–23, 11.15 п.2–3, 11.28, INV-006/007/008;
§11.39 items 12–14 (tool_semantics_version not in scope — parser has no tool
interaction).
Depends on: T10, T11, T12 (all Stage 2 code must exist).

## Goal

Comprehensive test coverage for the entire Stage 2 parser pipeline: grammar
acceptance/rejection, import orchestration, tenant isolation, idempotency,
credential sealing end-to-end, and architectural invariant guards. This task
exists to ensure cross-cutting concerns are tested as a whole, not just per-unit.

## Deliverables

No new source code. Only test files and test data fixtures.

### Test files

#### `tests/Feature/Parser/ImportIntegrationTest.php`

Full pipeline tests (parser → staging → Eloquent → encryption → access):

1. **Multi-format import**: import text with 5 different formats (socks5, http,
   mtproto, user:pass, bare host:port) → all 5 created correctly with proper
   `ProxyProtocol`, `CredentialKind`, and `CredentialEncryptor`-sealed envelopes.

2. **CIDR expansion + dedup**: import `10.0.0.0/30` → 4 endpoints created;
   re-import same CIDR → 0 created (4 skipped). Verify `proxy_endpoints` has
   exactly 4 rows.

3. **Mixed valid/invalid lines**: 10 lines, 3 valid, 4 VPN-rejected, 2 invalid
   format, 1 empty → `created = 3`, `parseErrors = 6`, `staged = 10`.
   All 10 have `RawFeedEntry` rows.

4. **Credential envelope end-to-end**: after import, verify each
   `ProxyCredential.secret_envelope` decrypts back to the original plaintext
   via `CredentialEncryptor::decrypt()`. Plaintext attribute is null after save.

5. **Access state initialization**: all created `ProxyAccess` rows have
   `state = New`, `testability_status = Testable`, `quarantine_status = None`.

6. **Endpoint identity consistency**: imported endpoints have correct
   `canonical_host`, `endpoint_identity_hash`, and `port` (default port
   applied when missing from input).

#### `tests/Feature/Parser/ParserScopeGuardTest.php`

Non-VPN guard tests (plan §1, INV-007 scope):

1. Each rejected scheme produces a `ParseError` with `NonVpnRejected` code:
   `vless://`, `vmess://`, `trojan://`, `ss://`, `wireguard://`, `openvpn://`.
   (These are unit-testable from T10 but also verified here end-to-end.)

2. VPN-rejected lines still get `RawFeedEntry` rows with `status = Error`.

3. Mixed input with VPN lines + valid lines: valid lines imported, VPN lines
   rejected, no partial failures.

#### `tests/Feature/Parser/TenantIsolationTest.php`

Negative tenant-scoping tests (INV-006):

1. Import as tenant A → verify tenant B's `ProxyEndpoint` query returns 0.
2. Import as tenant A → verify tenant B's `RawFeedEntry` query returns 0.
3. Two tenants import same proxy text → both have independent endpoints
   (same `endpoint_identity_hash`, different `tenant_id`).
4. Credential from tenant A is not decryptable by tenant B's DEK
   (cross-tenant ciphertext unreadable).
5. Call `ImportProxiesService::execute()` without `TenantContext` → exception.

#### `tests/Feature/Parser/IdempotencyTest.php`

Import idempotency (plan §§11.19, 11.37 R6.8):

1. Same text imported twice (no idempotency key) → second run creates 0
   new endpoints (deduped by identity hash).
2. Same text imported with same `idempotencyKey` → second call returns
   cached `ImportResult` without re-processing.
3. Same text imported with different `idempotencyKey` → both create
   (different batch runs, but endpoint dedup still applies).
4. `RawFeedEntry` unique constraint: same `(batch_hash, line_number)` in
   same tenant → second insert rejected.
5. Different tenant, same `(batch_hash, line_number)` → allowed (cross-tenant).

#### `tests/Feature/Parser/MtprotoParsingTest.php`

MTProto-specific integration (plan §§10.12 п.22, 11.35 п.12):

1. `mtproto://HEXSECRET@host:port` → credential with `kind = MtprotoSecret`.
2. `tg://proxy?server=host&port=port&secret=SECRET` → same.
3. `https://t.me/proxy?server=host&port=port&secret=SECRET` → same.
4. FakeTLS secret (hex with `ee` prefix) → parsed correctly, stored as-is.
5. Invalid MTProto secret (non-hex) → `InvalidMtprotoSecret` error.
6. MTProto endpoints have `protocol = Mtproto`, `port = 443` (default).

#### `tests/Unit/Parser/ParserAcceptanceVectorsTest.php`

Grammar-level acceptance vectors (plan §10.12 п.3) — pure, no DB:

1. All format variants from plan §10.12 п.3 parse correctly.
2. Default port assignment per scheme.
3. IPv6 with brackets (bare and with scheme).
4. CIDR expansion with limit.
5. Line number tracking (error at line N reports correct line).

#### `tests/Unit/Parser/ParserRejectionVectorsTest.php`

Grammar-level rejection vectors — pure, no DB:

1. All non-VPN schemes rejected with `NonVpnRejected`.
2. Invalid ports, missing hosts, malformed formats.
3. MTProto secret validation.
4. CIDR overflow.

#### `tests/Arch/ParserInvariantsTest.php`

Architectural invariant tests:

1. **INV-007**: grep `src/Domain/Parsing/` — no reference to
   `CredentialEncryptor`, `KekProvider`, `EncryptedField`, or any
   `Encryption\` class. Parser must never encrypt.
2. **INV-008**: `ProxyProtocol::Mtproto` is not treated as a transport
   in `Domain\Parsing` — no `connect()` calls, no transport-level code.
3. **INV-006**: `TenantContext` is not referenced in `Domain\Parsing`
   — the grammar library is tenant-unaware.

## Conventions

- Tests follow Pest syntax (`test()` / `it()` / `expect()`).
- Feature tests use `RefreshDatabase` (auto via `pest-plugin-laravel`).
- Use real `EndpointCanonicalizer` and `CredentialEncryptor` (no mocks
  for identity/encryption — integration correctness matters).
- Test data: inline strings, no external fixture files (keeps tests self-contained).
- Each test method covers one scenario; test names are descriptive.

## Verify

```
timeout 120 ../../../vendor/bin/pest --testsuite Feature
timeout 120 ../../../vendor/bin/pest --testsuite Unit
timeout 120 ../../../vendor/bin/pest
```

All tests must pass. This is the final Stage 2 validation — no code changes
allowed in this task, only tests.

## Out-of-scope

- Parser grammar implementation (T10), model code (T11), service code (T12).
- Performance / load testing (Stage 10+).
- External tool / worker contract tests (Stage 4+).
