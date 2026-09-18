<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Webconsulting\WorkosAuth\Domain\SocialProvider;
use WorkOS\Resource\UserManagementAuthenticationProvider;

final class SocialProviderTest extends TestCase
{
    public function testOffersTheFourSocialProvidersWithWorkosIdentifiers(): void
    {
        self::assertSame(
            ['GoogleOAuth', 'MicrosoftOAuth', 'GitHubOAuth', 'AppleOAuth'],
            array_map(static fn(SocialProvider $provider): string => $provider->value, SocialProvider::cases())
        );
    }

    public function testEveryProviderHasALabelKeyAndAnSdkCounterpart(): void
    {
        foreach (SocialProvider::cases() as $provider) {
            self::assertStringStartsWith('provider.', $provider->labelKey());
            self::assertSame(UserManagementAuthenticationProvider::from($provider->value), $provider->toSdk());
        }
    }

    public function testUnknownQueryValuesAreRejected(): void
    {
        self::assertNull(SocialProvider::tryFrom('FacebookOAuth'));
        self::assertNull(SocialProvider::tryFrom(''));
    }
}
