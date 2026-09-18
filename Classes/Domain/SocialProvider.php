<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Domain;

use WorkOS\Resource\UserManagementAuthenticationProvider;

/**
 * Social sign-in providers offered by the login templates and accepted by the
 * `?provider=` query parameter. Values are the identifiers WorkOS expects.
 */
enum SocialProvider: string
{
    case Google = 'GoogleOAuth';
    case Microsoft = 'MicrosoftOAuth';
    case GitHub = 'GitHubOAuth';
    case Apple = 'AppleOAuth';

    public function labelKey(): string
    {
        return match ($this) {
            self::Google => 'provider.google',
            self::Microsoft => 'provider.microsoft',
            self::GitHub => 'provider.github',
            self::Apple => 'provider.apple',
        };
    }

    public function toSdk(): UserManagementAuthenticationProvider
    {
        return UserManagementAuthenticationProvider::from($this->value);
    }
}
