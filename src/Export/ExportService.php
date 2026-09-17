<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Export;

/**
 * Orchestrates proxy export: loads views, applies filters, delegates to formatter.
 */
final class ExportService
{
    public function __construct(
        private ExportViewRepository $repository,
        private FormatterRegistry $registry,
    ) {}

    public function export(ExportQuery $query): ExportResult
    {
        $views = $this->repository->load($query);

        if ($query->tgReadyOnly) {
            $views = (new TelegramReadyFilter)->filter($views);
        }

        $formatter = $this->registry->get($query->format, $query->txtVariant);
        $content = $formatter->format($views);

        return ExportResult::make(
            content: $content,
            mimeType: $formatter->mimeType(),
            extension: $formatter->fileExtension(),
            recordCount: count($views),
        );
    }
}
