<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Functional\Service;

use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WorkosAuth\Domain\SocialProvider;
use Webconsulting\WorkosAuth\Security\MixedCaster;
use Webconsulting\WorkosAuth\Security\StateService;
use Webconsulting\WorkosAuth\Service\WorkosAuthenticationService;

/**
 * The hosted-login URL: PKCE on every request, and exactly one of the
 * three connection selectors WorkOS accepts. Building the URL makes no
 * network call, so these run without a WorkOS account.
 */
final class AuthorizationUrlTest extends FunctionalTestCase
{
    /**
     * @var array<non-empty-string>
     */
    protected array $testExtensionsToLoad = [
        'webconsulting/workos-auth',
    ];

    /**
     * @var array<string, mixed>
     */
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'workos_auth' => [
                'apiKey' => 'sk_test_dummy',
                'clientId' => 'client_dummy',
                'authkitOrganizationId' => 'org_configured',
            ],
        ],
    ];

    public function testEveryAuthorizationUsesPkceWithAVerifierKeptServerSide(): void
    {
        [$query, $cookie] = $this->authorize();

        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        $state = json_decode(MixedCaster::string($query['state'] ?? null), true, 4, JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertInstanceOf(Cookie::class, $cookie);

        $request = new ServerRequest('https://example.com/typo3/workos-auth/backend/callback')
            ->withCookieParams([$cookie->getName() => $cookie->getValue()]);
        $payload = $this->get(StateService::class)->peek($request, 'backend', MixedCaster::string($state['token'] ?? null));
        $verifier = MixedCaster::string($payload['codeVerifier'] ?? null);

        self::assertNotSame('', $verifier);
        self::assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            $query['code_challenge'] ?? null,
            'The challenge must be the S256 hash of the stored verifier'
        );
        self::assertStringNotContainsString($verifier, http_build_query($query), 'The verifier never leaves the server');
    }

    public function testAuthkitIsTheDefaultAndMayBePinnedToAnOrganization(): void
    {
        [$query] = $this->authorize();

        self::assertSame('authkit', $query['provider'] ?? null);
        self::assertSame('org_configured', $query['organization_id'] ?? null);
        self::assertArrayNotHasKey('connection_id', $query);
    }

    public function testASocialProviderIsTheOnlySelector(): void
    {
        [$query] = $this->authorize(SocialProvider::Google);

        self::assertSame('GoogleOAuth', $query['provider'] ?? null);
        self::assertArrayNotHasKey('organization_id', $query);
        self::assertArrayNotHasKey('connection_id', $query);
        self::assertArrayNotHasKey('screen_hint', $query);
    }

    public function testAConfiguredConnectionReplacesTheProvider(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['workos_auth']['authkitConnectionId'] = 'conn_sso';

        [$query] = $this->authorize();

        self::assertSame('conn_sso', $query['connection_id'] ?? null);
        self::assertArrayNotHasKey('provider', $query);
        self::assertArrayNotHasKey('organization_id', $query);
    }

    /**
     * @return array{0: array<array-key, mixed>, 1: Cookie|null}
     */
    private function authorize(?SocialProvider $provider = null): array
    {
        $result = $this->get(WorkosAuthenticationService::class)->buildBackendAuthorizationUrl(
            new ServerRequest('https://example.com/typo3/workos-auth/backend/login'),
            '/typo3',
            '/typo3/main',
            null,
            $provider,
        );
        parse_str(MixedCaster::string(parse_url($result['url'], PHP_URL_QUERY)), $query);

        return [$query, $result['cookie']];
    }
}
