<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Parser;

use BAGArt\ProxyOperations\Domain\Identity\CredentialKind;
use BAGArt\ProxyOperations\Domain\Identity\EndpointCanonicalizer;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Domain\Parsing\ImportProxiesCommand;
use BAGArt\ProxyOperations\Domain\Parsing\ImportResult;
use BAGArt\ProxyOperations\Domain\Parsing\ImportResultError;
use BAGArt\ProxyOperations\Domain\Parsing\ParsedEntry;
use BAGArt\ProxyOperations\Domain\Parsing\ParseError;
use BAGArt\ProxyOperations\Domain\Parsing\ParseResult;
use BAGArt\ProxyOperations\Domain\Parsing\ProxyListParser;
use BAGArt\ProxyOperations\Encryption\CredentialEncryptor;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyCredential;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Models\RawFeedEntry;
use BAGArt\ProxyOperations\Models\RawFeedEntryStatus;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tenancy\TenantNotResolvedException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Application service that imports proxy text into the inventory.
 *
 * Orchestrates: parse → RawFeedEntry staging → dedup → create ProxyEndpoint
 * + ProxyCredential (sealed via model boot) + ProxyAccess. One code path for
 * all entry points (bot /import, API, CLI, feeds — plan §11.10, §11.28).
 *
 * INV-006: all Eloquent operations run within TenantContext scope.
 * INV-007: the parser never encrypts; credential sealing happens in
 * ProxyCredential::booted() (T09 pattern).
 */
final class ImportProxiesService
{
    private const int MAX_ERROR_ENTRIES = 20;

    private const int BATCH_INSERT_CHUNK = 500;

    public function __construct(
        private readonly ProxyListParser $parser,
        private readonly CredentialEncryptor $encryptor,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @throws TenantNotResolvedException When no tenant is set in this scope.
     */
    public function execute(ImportProxiesCommand $command): ImportResult
    {
        $tenantId = $this->tenant->id();

        $normalizedText = trim(preg_replace('/\r\n?/', "\n", $command->text));
        $batchHash = hash('sha256', $normalizedText);

        if ($command->idempotencyKey !== null) {
            $cacheKey = "import:{$tenantId}:{$command->idempotencyKey}";
            $cached = Cache::get($cacheKey);

            if ($cached instanceof ImportResult) {
                return $cached;
            }
        }

        $parseResult = $this->parser->parse($command->text);

        $existingCount = RawFeedEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('batch_level_hash', $batchHash)
            ->count();

        if ($existingCount > 0) {
            $existingBatchId = RawFeedEntry::query()
                ->where('tenant_id', $tenantId)
                ->where('batch_level_hash', $batchHash)
                ->value('import_batch_id');

            return new ImportResult(
                totalLines: $parseResult->totalLines,
                created: 0,
                skipped: $parseResult->parsedCount,
                parseErrors: $parseResult->errorCount,
                staged: $existingCount,
                errors: array_map(
                    static fn (ParseError $e): ImportResultError => ImportResultError::fromParseError($e),
                    $parseResult->errors,
                ),
                importBatchId: (string) $existingBatchId,
            );
        }

        $importBatchId = (string) Str::uuid();

        $this->stageRawFeedEntries(
            tenantId: $tenantId,
            importBatchId: $importBatchId,
            batchHash: $batchHash,
            command: $command,
            parseResult: $parseResult,
        );

        $created = 0;
        $skipped = 0;
        $errors = [];

        $canonicalizer = new EndpointCanonicalizer;

        foreach ($parseResult->entries as $entry) {
            $result = $this->processEntry(
                tenantId: $tenantId,
                importBatchId: $importBatchId,
                batchHash: $batchHash,
                entry: $entry,
                canonicalizer: $canonicalizer,
            );

            if ($result === null) {
                $skipped++;
            } else {
                $created++;
            }
        }

        foreach ($parseResult->errors as $parseError) {
            $errors[] = ImportResultError::fromParseError($parseError);
        }

        $importResult = new ImportResult(
            totalLines: $parseResult->totalLines,
            created: $created,
            skipped: $skipped,
            parseErrors: $parseResult->errorCount,
            staged: count($parseResult->entries) + $parseResult->errorCount,
            errors: array_slice($errors, 0, self::MAX_ERROR_ENTRIES),
            importBatchId: $importBatchId,
        );

        if ($command->idempotencyKey !== null) {
            $cacheKey = "import:{$tenantId}:{$command->idempotencyKey}";
            Cache::put($cacheKey, $importResult, now()->addHour());
        }

        return $importResult;
    }

    private function stageRawFeedEntries(
        int $tenantId,
        string $importBatchId,
        string $batchHash,
        ImportProxiesCommand $command,
        ParseResult $parseResult,
    ): void {
        $allLines = explode("\n", $command->text);
        $lineStatuses = [];

        foreach ($parseResult->errors as $error) {
            $lineStatuses[$error->line] = [
                'status' => RawFeedEntryStatus::Error,
                'parse_error_json' => $error->jsonSerialize(),
                'parsed_entry_json' => null,
            ];
        }

        foreach ($parseResult->entries as $entry) {
            $lineStatuses[$entry->sourceLine] = [
                'status' => RawFeedEntryStatus::Parsed,
                'parsed_entry_json' => $entry->jsonSerialize(),
                'parse_error_json' => null,
            ];
        }

        $rows = [];
        foreach ($allLines as $index => $rawLine) {
            $lineNumber = $index + 1;
            $trimmed = trim($rawLine);

            if ($trimmed === '') {
                continue;
            }

            $statusData = $lineStatuses[$lineNumber] ?? [
                'status' => RawFeedEntryStatus::Error,
                'parse_error_json' => ['code' => 'unknown', 'detail' => 'Line not processed'],
                'parsed_entry_json' => null,
            ];

            $perLineHash = hash('sha256', $tenantId."\x00".$batchHash."\x00".$lineNumber);

            $rows[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'import_batch_id' => $importBatchId,
                'batch_hash' => $perLineHash,
                'batch_level_hash' => $batchHash,
                'raw_line' => mb_substr($rawLine, 0, 4096),
                'line_number' => $lineNumber,
                'status' => $statusData['status']->value,
                'parsed_entry_json' => $statusData['parsed_entry_json'] !== null
                    ? json_encode($statusData['parsed_entry_json'])
                    : null,
                'parse_error_json' => $statusData['parse_error_json'] !== null
                    ? json_encode($statusData['parse_error_json'])
                    : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, self::BATCH_INSERT_CHUNK) as $chunk) {
            try {
                RawFeedEntry::query()->insert($chunk);
            } catch (UniqueConstraintViolationException) {
                // Batch already staged by a concurrent import — skip gracefully.
            }
        }
    }

    private function processEntry(
        int $tenantId,
        string $importBatchId,
        string $batchHash,
        ParsedEntry $entry,
        EndpointCanonicalizer $canonicalizer,
    ): ?ProxyEndpoint {
        $identity = $canonicalizer->canonicalize(
            host: $entry->host,
            port: $entry->port,
            protocol: ProxyProtocol::from($entry->scheme),
        );

        $identityHash = ProxyEndpoint::identityHash($identity);

        $existing = ProxyEndpoint::query()
            ->where('endpoint_identity_hash', $identityHash)
            ->first();

        if ($existing !== null) {
            $this->markFeedEntrySkipped(
                tenantId: $tenantId,
                importBatchId: $importBatchId,
                batchHash: $batchHash,
                lineNumber: $entry->sourceLine,
                endpointId: $existing->id,
            );

            return null;
        }

        $endpoint = ProxyEndpoint::fromIdentity(
            identity: $identity,
            originalHost: $entry->originalHost,
        );
        $endpoint->save();

        $credentialId = null;
        if ($entry->secret !== null || $entry->username !== null) {
            $credential = ProxyCredential::create([
                'endpoint_id' => $endpoint->id,
                'kind' => $this->credentialKind($entry),
                'username' => $entry->username,
                'secret' => $entry->secret ?? '',
            ]);
            $credentialId = $credential->id;
        }

        ProxyAccess::create([
            'endpoint_id' => $endpoint->id,
            'credential_id' => $credentialId,
        ]);

        return $endpoint;
    }

    private function markFeedEntrySkipped(
        int $tenantId,
        string $importBatchId,
        string $batchHash,
        int $lineNumber,
        string $endpointId,
    ): void {
        $perLineHash = hash('sha256', $tenantId."\x00".$batchHash."\x00".$lineNumber);

        RawFeedEntry::query()
            ->where('batch_hash', $perLineHash)
            ->where('line_number', $lineNumber)
            ->update([
                'status' => RawFeedEntryStatus::Skipped->value,
                'endpoint_id' => $endpointId,
            ]);
    }

    private function credentialKind(ParsedEntry $entry): CredentialKind
    {
        return match (ProxyProtocol::from($entry->scheme)) {
            ProxyProtocol::Http, ProxyProtocol::Https => CredentialKind::BasicAuth,
            ProxyProtocol::Mtproto => CredentialKind::MtprotoSecret,
            default => CredentialKind::SocksAuth,
        };
    }
}
