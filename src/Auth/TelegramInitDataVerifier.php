<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Auth;

use DateTimeImmutable;

/**
 * Validated Telegram Mini App initData (plan §11.29, §10.12 п.56).
 */
final readonly class TelegramInitData
{
    public function __construct(
        public int $userId,
        public string $username,
        public string $firstName,
        public ?string $lastName,
        public int $authDate,
        public string $hash,
    ) {}

    public function isExpired(int $maxAgeSeconds = 86400): bool
    {
        $now = new DateTimeImmutable;

        return $now->getTimestamp() - $this->authDate > $maxAgeSeconds;
    }
}

/**
 * HMAC verification of Telegram Mini App initData (plan §11.29).
 *
 * @see https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 */
final class TelegramInitDataVerifier
{
    public function __construct(
        private string $botToken,
    ) {}

    /**
     * Parse and validate initData string.
     */
    public function verify(string $initData): ?TelegramInitData
    {
        $pairs = parse_str($initData, $parsed);

        if ($parsed === false || ! isset($parsed['hash'])) {
            return null;
        }

        $hash = $parsed['hash'];
        unset($parsed['hash']);

        ksort($parsed);

        $dataCheckString = [];
        foreach ($parsed as $key => $value) {
            $dataCheckString[] = "{$key}={$value}";
        }
        $dataCheckString = implode("\n", $dataCheckString);

        $secretKey = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
        $computedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (! hash_equals($computedHash, $hash)) {
            return null;
        }

        $user = json_decode($parsed['user'] ?? '{}', true);

        return new TelegramInitData(
            userId: (int) ($user['id'] ?? 0),
            username: $user['username'] ?? '',
            firstName: $user['first_name'] ?? '',
            lastName: $user['last_name'] ?? null,
            authDate: (int) ($parsed['auth_date'] ?? 0),
            hash: $hash,
        );
    }
}
