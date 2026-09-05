<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

/**
 * Allowed secret delivery mechanisms (plan §11.39 п.7): credentials never go
 * through argv (INV-013), so the command builder may only wire stdin/pipe or
 * a dedicated Unix file descriptor.
 */
enum CredentialDeliveryMode: string
{
    case Stdin = 'stdin';
    case FileDescriptor = 'file_descriptor';
}
