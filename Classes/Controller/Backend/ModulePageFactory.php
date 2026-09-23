<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Controller\Backend;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use Webconsulting\WorkosAuth\Service\LabelTranslator;

/**
 * The shared frame of the three WorkOS modules: title, document header
 * (shortcut, and Save for a module that edits settings) and flash messages
 * in the Core queue the Module layout renders.
 */
final readonly class ModulePageFactory
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ComponentFactory $componentFactory,
        private FlashMessageService $flashMessageService,
        private LabelTranslator $translator,
    ) {}

    /**
     * @param string $saveFormId id of the settings form the Save button submits; none when empty
     */
    public function create(ServerRequestInterface $request, string $routeIdentifier, string $titleKey, string $saveFormId = ''): ModuleTemplate
    {
        $title = $this->translator->translate($titleKey);
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($title);
        $view->getDocHeaderComponent()->setShortcutContext($routeIdentifier, $title);
        if ($saveFormId !== '') {
            $view->addButtonToButtonBar(
                $this->componentFactory->createSaveButton($saveFormId)->setShowLabelText(true),
                ButtonBar::BUTTON_POSITION_LEFT,
                10,
            );
        }

        return $view;
    }

    /**
     * Queues a message for the next module page, e.g. after a redirect.
     */
    public function flash(string $message, ContextualFeedbackSeverity $severity, string $title = ''): void
    {
        $this->flashMessageService
            ->getMessageQueueByIdentifier(FlashMessageQueue::FLASHMESSAGE_QUEUE)
            ->addMessage(new FlashMessage($message, $title, $severity, true));
    }
}
