<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Failure;

/**
 * Responsibility axis of a failure (plan §11.16, round 7).
 *
 * Answers "whose fault is it" so checker infrastructure failures can never be
 * misread as proxy failures (INV-014/015).
 */
enum FailureClass: string
{
    case Proxy = 'proxy';
    case Target = 'target';
    case Judge = 'judge';
    case Checker = 'checker';
    case Platform = 'platform';
    case Policy = 'policy';

    public function isExecutionFailure(): bool
    {
        return in_array($this, [self::Checker, self::Platform], true);
    }
}
