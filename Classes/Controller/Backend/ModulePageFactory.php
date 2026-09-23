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
 * (shortcut, reload, and Save for a module that edits settings) and flash
 * messages in the Core queue the Module layout renders.
 *
 * Save and reload carry this extension's labels, so they follow the backend
 * user's language even where no Core language pack is installed.
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
        $docHeader = $view->getDocHeaderComponent();
        $docHeader->setShortcutContext($routeIdentifier, $title);
        $docHeader->disableAutomaticReloadButton();
        $view->addButtonToButtonBar(
            $this->componentFactory->createReloadButton($request->getUri())->setTitle($this->translator->translate('module.button.reload')),
            ButtonBar::BUTTON_POSITION_RIGHT,
            90,
        );
        if ($saveFormId !== '') {
            $view->addButtonToButtonBar(
                $this->componentFactory->createSaveButton($saveFormId)
                    ->setTitle($this->translator->translate('module.button.save'))
                    ->setShowLabelText(true),
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
