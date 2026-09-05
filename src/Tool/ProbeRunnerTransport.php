<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

/**
 * Transport between the Audit Worker and the Probe Runner (plan §11.39 п.3):
 * UnixSocket for a local container (best IPC), HTTPS+mTLS for a remote checker
 * node — one architecture covers local, separate Docker, separate machine and
 * regional fleet deployments.
 */
enum ProbeRunnerTransport: string
{
    case UnixSocket = 'unix_socket';
    case HttpsMtls = 'https_mtls';
}
