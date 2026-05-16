<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use WebConsulting\WorkosAuth\Service\RequestBody;

final class RequestBodyTest extends TestCase
{
    public function testFromRequestWithArrayBodyReturnsValues(): void
    {
        $request = (new ServerRequest(new Uri('https://app.local/login')))
            ->withParsedBody(['email' => '  user@example.com  ', 'other' => 42]);

        $body = RequestBody::fromRequest($request);

        self::assertSame('user@example.com', $body->trimmedString('email'));
        self::assertSame('42', $body->string('other'));
        self::assertTrue($body->has('email'));
    }

    public function testFromRequestWithNullBodyFallsBackToEmptyArray(): void
    {
        $request = new ServerRequest(new Uri('https://app.local/login'));
        $body = RequestBody::fromRequest($request);

        self::assertFalse($body->has('missing'));
        self::assertSame('', $body->string('missing'));
        self::assertSame('default', $body->string('missing', 'default'));
    }

    public function testFromRequestWithObjectBodyFallsBackToEmptyArray(): void
    {
        $request = (new ServerRequest(new Uri('https://app.local/login')))
            ->withParsedBody((object)['email' => 'user@example.com']);

        $body = RequestBody::fromRequest($request);
        self::assertSame('', $body->string('email'));
    }

    public function testStringReturnsDefaultForNonScalars(): void
    {
        $request = (new ServerRequest(new Uri('https://app.local/login')))
            ->withParsedBody(['payload' => ['nested' => 'value']]);

        $body = RequestBody::fromRequest($request);
        self::assertSame('fallback', $body->string('payload', 'fallback'));
    }

    public function testToArrayReturnsNarrowedCopy(): void
    {
        $request = (new ServerRequest(new Uri('https://app.local/login')))
            ->withParsedBody(['a' => 1, 'b' => 'two']);
        $body = RequestBody::fromRequest($request);

        self::assertSame(['a' => 1, 'b' => 'two'], $body->toArray());
    }
}
