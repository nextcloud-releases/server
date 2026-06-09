<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, int, int, bool, bool}>
     */
    public static function tags(): iterable
    {
        // tag, major, minor, patch, isPrerelease, isFirstBeta
        yield 'stable patch'      => ['v34.0.4', 34, 0, 4, false, false];
        yield 'stable patch no-v' => ['34.0.4', 34, 0, 4, false, false];
        yield 'initial major'     => ['v34.0.0', 34, 0, 0, false, false];
        yield 'rc'                => ['v34.0.0rc1', 34, 0, 0, true, false];
        yield 'beta (not first)'  => ['v34.0.1beta2', 34, 0, 1, true, false];
        yield 'first beta'        => ['v35.0.0beta1', 35, 0, 0, true, true];
        yield 'alpha'             => ['v36.0.0alpha1', 36, 0, 0, true, false];
        yield 'minor bump'        => ['v34.1.2', 34, 1, 2, false, false];
    }

    #[DataProvider('tags')]
    public function testFromTag(string $tag, int $major, int $minor, int $patch, bool $pre, bool $firstBeta): void
    {
        $v = Version::fromTag($tag);
        $this->assertSame($major, $v->major);
        $this->assertSame($minor, $v->minor);
        $this->assertSame($patch, $v->patch);
        $this->assertSame($pre, $v->isPrerelease);
        $this->assertSame($firstBeta, $v->isFirstBeta);
    }
}
