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
// That package is a React library, so we load React, ReactDOM and the
// widgets bundle from esm.sh at runtime. The widget itself talks to
// api.workos.com and loads avatars from WorkOS CDNs.

return Map::fromEntries([
    Scope::backend(),
    new MutationCollection(
        new Mutation(
            MutationMode::Extend,
            Directive::ScriptSrc,
            new UriValue('https://esm.sh'),
            new UriValue('https://cdn.esm.sh'),
        ),
        new Mutation(
            MutationMode::Extend,
            Directive::StyleSrc,
            new UriValue('https://esm.sh'),
            new UriValue('https://cdn.esm.sh'),
            SourceKeyword::unsafeInline,
        ),
        new Mutation(
            MutationMode::Extend,
            Directive::ConnectSrc,
            new UriValue('https://esm.sh'),
            new UriValue('https://cdn.esm.sh'),
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
            new UriValue('https://esm.sh'),
            new UriValue('https://cdn.esm.sh'),
            SourceScheme::data,
        ),
    ),
]);
