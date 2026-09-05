<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Identity;

/**
 * Protocol-specific credential kinds (plan §11.3): a single username/password
 * model is wrong — MTProto secrets have their own storage, masking, validation
 * and export policy, separate from user/pass authentication.
 */
enum CredentialKind: string
{
    case BasicAuth = 'basic_auth';
    case SocksAuth = 'socks_auth';
    case MtprotoSecret = 'mtproto_secret';
}
