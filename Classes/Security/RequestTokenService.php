<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Security;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\SecurityAspect;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class RequestTokenService
{
    public function create(string $scope): RequestToken
    {
        return RequestToken::create($scope);
    }

    public function createHashed(string $scope): string
    {
        $securityAspect = $this->getSecurityAspect();

        return RequestToken::create($scope)->toHashSignedJwt(
            $securityAspect->provideNonce()
        );
    }

    public function validate(string $scope): bool
    {
        $securityAspect = $this->getSecurityAspect();
        $requestToken = $securityAspect->getReceivedRequestToken();

        if (!$requestToken instanceof RequestToken || $requestToken->scope !== $scope) {
            return false;
        }

        if ($requestToken->getSigningSecretIdentifier() !== null) {
            $securityAspect->getSigningSecretResolver()->revokeIdentifier(
                $requestToken->getSigningSecretIdentifier()
            );
        }

        return true;
    }

    private function getSecurityAspect(): SecurityAspect
    {
        return SecurityAspect::provideIn(
            GeneralUtility::makeInstance(Context::class)
        );
    }
}
