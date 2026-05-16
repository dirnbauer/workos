<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Authentication;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

final class WorkosFrontendUserAuthentication extends FrontendUserAuthentication
{
    /**
     * @param array<string, mixed> $userRow
     */
    public function signIn(array $userRow, ServerRequestInterface $request): void
    {
        $this->initializeUserSessionManager();
        $this->user = $userRow;
        $this->userSession = $this->createUserSession($userRow);
        $this->loginSessionStarted = true;
        $this->fetchGroupData($request);
    }

    public function signOut(ServerRequestInterface $request): void
    {
        $this->start($request);
        $this->logoff();
    }
}
