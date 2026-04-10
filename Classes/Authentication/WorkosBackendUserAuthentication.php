<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Authentication;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class WorkosBackendUserAuthentication extends BackendUserAuthentication
{
    public function signIn(array $userRow, ServerRequestInterface $request): void
    {
        $this->initializeUserSessionManager();
        $this->user = $userRow;
        $this->userSession = $this->createUserSession($userRow);
        $this->loginSessionStarted = true;
        $this->initializeBackendLogin($request);
    }
}
