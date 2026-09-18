<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use Webconsulting\WorkosAuth\Service\RequestBody;

final class RequestBodyTest extends TestCase
{
    public function testFromRequestWithArrayBodyReturnsValues(): void
    {
        $body = RequestBody::fromRequest(self::request(['email' => '  user@example.com  ', 'other' => 42]));

        self::assertSame('user@example.com', $body->trimmedString('email'));
        self::assertSame('42', $body->string('other'));
    }

    public function testMissingKeysFallBackToTheDefault(): void
    {
        $body = RequestBody::fromRequest(new ServerRequest(new Uri('https://app.local/login')));

        self::assertSame('', $body->string('missing'));
        self::assertSame('default', $body->string('missing', 'default'));
        self::assertSame([], $body->group('configuration'));
    }

    public function testObjectBodiesAreTreatedAsEmpty(): void
    {
        $body = RequestBody::fromRequest(self::request((object)['email' => 'user@example.com']));

        self::assertSame('', $body->string('email'));
    }

    public function testStringReturnsDefaultForNonScalars(): void
    {
        $body = RequestBody::fromRequest(self::request(['payload' => ['nested' => 'value']]));

        self::assertSame('fallback', $body->string('payload', 'fallback'));
    }

    public function testGroupReturnsNestedFormValues(): void
    {
        $body = RequestBody::fromRequest(self::request(['configuration' => ['apiKey' => 'sk', 7 => 'x'], 'flat' => 'y']));

        self::assertSame(['apiKey' => 'sk', '7' => 'x'], $body->group('configuration'));
        self::assertSame([], $body->group('flat'));
    }

    /**
     * @param array<string, mixed>|object $parsedBody
     */
    private static function request(array|object $parsedBody): ServerRequest
    {
        return (new ServerRequest(new Uri('https://app.local/login')))->withParsedBody($parsedBody);
    }
}
