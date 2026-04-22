<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Unit\Authentication;

use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use WebConsulting\WorkosAuth\Authentication\WorkosTypo3AuthenticationService;

final class WorkosTypo3AuthenticationServiceTest extends TestCase
{
    public function testProcessLoginDataDoesNothingWithoutPendingLogin(): void
    {
        $service = new WorkosTypo3AuthenticationService();
        $request = new ServerRequest(new Uri('https://app.local/'));
        $loginData = ['status' => 'login', 'uname' => '', 'uident' => ''];

        $service->initAuth('processLoginDataFE', $loginData, ['request' => $request], new FrontendUserAuthentication());

        self::assertFalse($service->processLoginData($loginData));
        self::assertSame('', $loginData['uname']);
        self::assertSame('', $loginData['uident']);
    }

    public function testProcessLoginDataInjectsPlaceholdersWhenPendingLoginExists(): void
    {
        $service = new WorkosTypo3AuthenticationService();
        $request = (new ServerRequest(new Uri('https://app.local/')))
            ->withAttribute(WorkosTypo3AuthenticationService::PENDING_LOGIN_ATTRIBUTE, [
                'context' => 'frontend',
                'user' => ['uid' => 42, 'username' => 'workos_user'],
            ]);
        $loginData = ['status' => 'login', 'uname' => '', 'uident' => ''];

        $service->initAuth('processLoginDataFE', $loginData, ['request' => $request], new FrontendUserAuthentication());

        self::assertSame(200, $service->processLoginData($loginData));
        self::assertSame('__workos__', $loginData['uname']);
        self::assertSame('__workos__', $loginData['uident']);
        self::assertSame('__workos__', $loginData['uident_text']);
    }

    public function testGetUserReturnsPendingFrontendUser(): void
    {
        $service = new WorkosTypo3AuthenticationService();
        $request = (new ServerRequest(new Uri('https://app.local/')))
            ->withAttribute(WorkosTypo3AuthenticationService::PENDING_LOGIN_ATTRIBUTE, [
                'context' => 'frontend',
                'user' => ['uid' => 7, 'username' => 'frontend_user'],
            ]);
        $loginData = ['status' => 'login'];

        $service->initAuth('getUserFE', $loginData, ['request' => $request], new FrontendUserAuthentication());

        self::assertSame(['uid' => 7, 'username' => 'frontend_user'], $service->getUser());
    }

    public function testAuthUserAcceptsMatchingPendingBackendUser(): void
    {
        $service = new WorkosTypo3AuthenticationService();
        $request = (new ServerRequest(new Uri('https://app.local/typo3/login')))
            ->withAttribute(WorkosTypo3AuthenticationService::PENDING_LOGIN_ATTRIBUTE, [
                'context' => 'backend',
                'user' => ['uid' => 99, 'username' => 'backend_user'],
            ]);
        $loginData = ['status' => 'login'];

        $service->initAuth('authUserBE', $loginData, ['request' => $request], new BackendUserAuthentication());

        self::assertSame(200, $service->authUser(['uid' => 99, 'username' => 'backend_user']));
    }

    public function testAuthUserRejectsMismatchedPendingUser(): void
    {
        $service = new WorkosTypo3AuthenticationService();
        $request = (new ServerRequest(new Uri('https://app.local/typo3/login')))
            ->withAttribute(WorkosTypo3AuthenticationService::PENDING_LOGIN_ATTRIBUTE, [
                'context' => 'backend',
                'user' => ['uid' => 99, 'username' => 'backend_user'],
            ]);
        $loginData = ['status' => 'login'];

        $service->initAuth('authUserBE', $loginData, ['request' => $request], new BackendUserAuthentication());

        self::assertSame(0, $service->authUser(['uid' => 100, 'username' => 'someone_else']));
    }
}
