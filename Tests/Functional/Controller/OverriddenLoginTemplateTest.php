<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Functional\Controller;

use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WorkosAuth\Security\MixedCaster;

/**
 * Sitepackages override the Login templates and still pass `returnToUrl`
 * as link argument. Without a requested target the variable is null, so
 * such a link carries no `returnTo` either.
 */
final class OverriddenLoginTemplateTest extends FunctionalTestCase
{
    private const string BASE = 'https://website.local';

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
                'frontendAutoCreateUsers' => '0',
            ],
        ],
        'FE' => [
            'cacheHash' => [
                'excludedParameters' => ['returnTo'],
            ],
        ],
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'is_siteroot' => 1, 'doktype' => 1]);
        $pages->insert('pages', ['uid' => 2, 'pid' => 1, 'title' => 'Login', 'slug' => '/login', 'doktype' => 1]);
        $this->get(SiteWriter::class)->createNewBasicSite('website', 1, self::BASE . '/');
        $this->setUpFrontendRootPage(1, [
            'EXT:workos_auth/Tests/Functional/Fixtures/LoginPlugin.typoscript',
            'EXT:workos_auth/Tests/Functional/Fixtures/TemplateOverride232.typoscript',
        ]);
    }

    public function testALinkOfAnOverriddenTemplateCarriesNoEmptyTarget(): void
    {
        $html = $this->render('/login');

        self::assertStringContainsString('Sign up', $html, 'The overriding template is rendered');
        self::assertStringNotContainsString('returnTo', $this->signUpLink($html));
    }

    public function testALinkOfAnOverriddenTemplateKeepsARequestedTarget(): void
    {
        $link = $this->signUpLink($this->render('/login?returnTo=' . rawurlencode('/members?tab=2')));

        parse_str(MixedCaster::string(parse_url($link, PHP_URL_QUERY)), $query);
        self::assertSame('/members?tab=2', MixedCaster::stringKeyedArray($query['tx_workosauth_login'] ?? null)['returnTo'] ?? null);
    }

    private function render(string $pathAndQuery): string
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest(self::BASE . $pathAndQuery));
        self::assertSame(200, $response->getStatusCode(), $pathAndQuery);

        return (string)$response->getBody();
    }

    private function signUpLink(string $html): string
    {
        self::assertSame(1, preg_match('/href="([^"]*signUp[^"]*)"/', $html, $link), 'The sign-up link is rendered');

        return html_entity_decode($link[1], ENT_QUOTES | ENT_HTML5);
    }
}
