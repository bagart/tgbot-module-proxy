<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Test model deliberately NOT using BelongsToTenant, proving the trait does
 * not leak scoping onto unmarked models.
 */
final class UntenantedThing extends Model
{
    protected $table = 'untenancy_test_things';

    protected $guarded = [];
}
