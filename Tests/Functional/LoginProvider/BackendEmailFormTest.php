<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Functional\LoginProvider;

use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Fluid\View\FluidViewAdapter;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class BackendEmailFormTest extends FunctionalTestCase
{
    /** @var array<non-empty-string> */
    protected array $testExtensionsToLoad = ['webconsulting/workos-auth'];

    public function testEmailCodeAndPasswordSignInHaveTheirOwnLabelledEmailFields(): void
    {
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templateRootPaths: ['EXT:workos_auth/Resources/Private/Templates'],
            partialRootPaths: ['EXT:workos_auth/Resources/Private/Partials'],
        ));
        self::assertInstanceOf(FluidViewAdapter::class, $view);
        $view->getRenderingContext()->getTemplatePaths()->setTemplatePathAndFilename(
            'EXT:workos_auth/Resources/Private/Templates/Login/WorkosLoginProvider.html'
        );
        $html = $view->renderSection('SignIn', ['socialProviders' => [], 'loginUrl' => '/typo3/workos-auth/backend/login']);
        self::assertIsString($html);
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);

        self::assertSame(1.0, $xpath->evaluate('count(//*[@data-workos-form="magic-send"]//input[@type="email" and @name="email"])'));
        self::assertSame(1.0, $xpath->evaluate('count(//*[@data-workos-form="password"]//input[@type="email" and @name="email"])'));
        self::assertSame(0.0, $xpath->evaluate('count(//*[@data-workos-form="magic-send"]//input[@type="password"])'));
        self::assertSame(1.0, $xpath->evaluate('count(//label[@for="workos-magic-email"])'));
        self::assertStringContainsString('Sign in by email', $html);
        self::assertStringNotContainsString('{f:', $html);
    }
}
