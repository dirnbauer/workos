<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceKeyword;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceScheme;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Type\Map;

// The backend module "workos_users" embeds the official WorkOS User
// Management widget (https://workos.com/docs/widgets/user-management).
// The widget is pre-bundled and shipped with this extension, so no
// third-party script/style CDNs are needed. We only relax the backend
// CSP for the WorkOS API (XHR) and the WorkOS avatar CDN (images).

return Map::fromEntries([
    Scope::backend(),
    new MutationCollection(
        new Mutation(
            MutationMode::Extend,
            Directive::StyleSrc,
            SourceKeyword::unsafeInline,
        ),
        new Mutation(
            MutationMode::Extend,
            Directive::ConnectSrc,
            new UriValue('https://api.workos.com'),
        ),
        new Mutation(
            MutationMode::Extend,
            Directive::ImgSrc,
            new UriValue('https://*.workoscdn.com'),
            new UriValue('https://workoscdn.com'),
            new UriValue('https://api.workos.com'),
            SourceScheme::data,
        ),
        new Mutation(
            MutationMode::Extend,
            Directive::FontSrc,
            SourceScheme::data,
        ),
    ),
]);
