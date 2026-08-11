<?php

declare(strict_types=1);

namespace Pressless\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use Pressless\Content\ValidationResult;

final class ValidationResultTest extends TestCase
{
    public function testOkHasNoErrors(): void
    {
        $result = ValidationResult::ok();
        $this->assertFalse($result->hasErrors());
        $this->assertSame([], $result->errors());
        $this->assertSame([], $result->errorsFor('any'));
    }

    public function testFromErrorsReportsGroupedMessages(): void
    {
        $result = ValidationResult::fromErrors([
            'title' => ['Required.'],
            'body' => ['Too short.', 'Too long.'],
        ]);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(['Required.'], $result->errorsFor('title'));
        $this->assertSame(['Too short.', 'Too long.'], $result->errorsFor('body'));
    }

    public function testFromErrorsDropsEmptyAndInvalidEntries(): void
    {
        $result = ValidationResult::fromErrors([
            'title' => ['Required.'],
            'body' => [],
            'rating' => 'not-an-array',
            '' => ['orphan'],
        ]);

        $this->assertSame(['title' => ['Required.']], $result->errors());
    }
}
