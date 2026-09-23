<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Functional\EventListener;

use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Policy;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceKeyword;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WorkosAuth\EventListener\AllowUserManagementWidgetSources;

/**
 * The widget's sources are allowed on its module page and nowhere else in
 * the backend.
 */
final class AllowUserManagementWidgetSourcesTest extends FunctionalTestCase
{
    /**
     * @var array<non-empty-string>
     */
    protected array $testExtensionsToLoad = [
        'webconsulting/workos-auth',
    ];

    public function testTheUserManagementModuleMayTalkToWorkos(): void
    {
        $policy = $this->mutatedPolicy(Scope::backend(), '/typo3/module/workos/users');

        self::assertTrue($policy->containsDirective(Directive::ConnectSrc, new UriValue('https://api.workos.com')));
        self::assertTrue($policy->containsDirective(Directive::ImgSrc, new UriValue('https://*.workoscdn.com')));
    }

    public function testOtherBackendPagesKeepTheCorePolicy(): void
    {
        foreach (['/typo3/module/workos/setup', '/typo3/module/web/layout', '/typo3/main', '/typo3/module/workos/users/token'] as $path) {
            $policy = $this->mutatedPolicy(Scope::backend(), $path);

            self::assertFalse($policy->containsDirective(Directive::ConnectSrc, new UriValue('https://api.workos.com')), $path);
        }
    }

    public function testTheFrontendIsLeftAlone(): void
    {
        $policy = $this->mutatedPolicy(Scope::frontend(), '/typo3/module/workos/users');

        self::assertFalse($policy->containsDirective(Directive::ConnectSrc, new UriValue('https://api.workos.com')));
    }

    private function mutatedPolicy(Scope $scope, string $path): Policy
    {
        $policy = new Policy(SourceKeyword::self);
        $event = new PolicyMutatedEvent($scope, new ServerRequest('https://example.com' . $path), $policy, $policy);
        $this->get(AllowUserManagementWidgetSources::class)($event);

        return $event->getCurrentPolicy();
    }
}
