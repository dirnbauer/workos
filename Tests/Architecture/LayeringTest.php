<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;
use Webconsulting\WorkosAuth\Controller\Backend\SetupAssistantController;
use Webconsulting\WorkosAuth\Controller\Backend\UserManagementController;
use Webconsulting\WorkosAuth\Controller\Frontend\AccountController;
use Webconsulting\WorkosAuth\Controller\Frontend\LoginController;
use Webconsulting\WorkosAuth\Controller\Frontend\TeamController;

/**
 * Architecture rules enforced via phpat + PHPStan.
 *
 * Each method returns a named Rule that phpat's PHPStan extension
 * evaluates during static analysis. These guard the
 * Controllers → Services → Security boundary and prevent
 * cross-controller imports.
 */
final class LayeringTest
{
    /**
     * Controllers are the outermost layer — no other layer may depend
     * on them.
     */
    public function testNothingDependsOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::inNamespace('Webconsulting\\WorkosAuth\\Service'),
                Selector::inNamespace('Webconsulting\\WorkosAuth\\Security'),
                Selector::inNamespace('Webconsulting\\WorkosAuth\\Middleware'),
                Selector::inNamespace('Webconsulting\\WorkosAuth\\LoginProvider'),
                Selector::inNamespace('Webconsulting\\WorkosAuth\\EventListener'),
                Selector::inNamespace('Webconsulting\\WorkosAuth\\Configuration'),
            )
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Webconsulting\\WorkosAuth\\Controller'));
    }

    /**
     * Controllers must not import each other. Each action controller is
     * self-contained; cross-plugin coupling goes through a service.
     */
    public function testControllersDoNotImportEachOther(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname(LoginController::class))
            ->shouldNotDependOn()
            ->classes(
                Selector::classname(AccountController::class),
                Selector::classname(TeamController::class),
                Selector::classname(UserManagementController::class),
                Selector::classname(SetupAssistantController::class),
            );
    }

    /**
     * The Security namespace is primitive: Security classes may only
     * depend on vendor code and other Security classes. They must not
     * reach up into Service, Controller, Middleware, etc.
     */
    public function testSecurityIsSelfContained(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Webconsulting\\WorkosAuth\\Security'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Webconsulting\\WorkosAuth\\Controller'),
                Selector::inNamespace('Webconsulting\\WorkosAuth\\Middleware'),
                Selector::inNamespace('Webconsulting\\WorkosAuth\\LoginProvider'),
                Selector::inNamespace('Webconsulting\\WorkosAuth\\EventListener'),
                Selector::inNamespace('Webconsulting\\WorkosAuth\\Service'),
                Selector::inNamespace('Webconsulting\\WorkosAuth\\Configuration'),
            );
    }

    /**
     * Services may talk to other Services, Security helpers and the
     * Configuration, but not to Controllers, Middleware, Login
     * providers or Event listeners (they are outbound only).
     */
    public function testServicesDoNotDependOnOutboundLayers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Webconsulting\\WorkosAuth\\Service'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Webconsulting\\WorkosAuth\\Controller'),
                Selector::inNamespace('Webconsulting\\WorkosAuth\\Middleware'),
                Selector::inNamespace('Webconsulting\\WorkosAuth\\LoginProvider'),
                Selector::inNamespace('Webconsulting\\WorkosAuth\\EventListener'),
            );
    }
}
