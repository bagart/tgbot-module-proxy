<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

/**
 * Secret delivered over the tool's standard input (plan §11.39 п.7): the
 * default channel — no file, no argv, nothing in the process list.
 */
final readonly class StdinChannel implements CredentialChannel
{
    public function mode(): CredentialDeliveryMode
    {
        return CredentialDeliveryMode::Stdin;
    }
}
