<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

/**
 * Delivery plan for probe credentials (plan §11.39 пп.6–7). The channel never
 * carries or exposes secret material — it only tells the CLI adapter HOW to
 * wire the pipe (argv flags, redirections) while the runner runtime feeds the
 * secret through the OS-level channel and zeroizes it afterwards (INV-013).
 */
interface CredentialChannel
{
    public function mode(): CredentialDeliveryMode;
}
