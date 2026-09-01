<?php

namespace APP\plugins\generic\nastis;

use APP\plugins\generic\nastis\classes\hooks\HookRegistrant;
use APP\plugins\generic\nastis\classes\services\NastisSyncService;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;

class NastisPlugin extends \PKP\plugins\GenericPlugin
{
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);

        if ($success && $this->getEnabled()) {
            (new HookRegistrant($this))->register();
        }

        return $success;
    }

    public function getDisplayName()
    {
        return __('plugins.generic.nastis.name');
    }

    public function getDescription()
    {
        return __('plugins.generic.nastis.description');
    }

    public function getActions($request, $verb)
    {
        $parentActions = parent::getActions($request, $verb);

        if (!$this->getEnabled()) {
            return $parentActions;
        }

        $router = $request->getRouter();
        $linkAction = new LinkAction(
            'settings',
            new AjaxModal(
                $router->url(
                    $request,
                    null,
                    null,
                    'manage',
                    null,
                    [
                        'verb' => 'settings',
                        'plugin' => $this->getName(),
                        'category' => 'generic',
                    ]
                ),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        );

        array_unshift($parentActions, $linkAction);

        return $parentActions;
    }

    public function manage($args, $request)
    {
        $verb = $request->getUserVar('verb');

        if ($verb === 'testConnection') {
            return $this->testConnection($request);
        }

        if ($verb !== 'settings') {
            return parent::manage($args, $request);
        }

        $context = $request->getContext();
        $form = new NastisSettingsForm($this, $context->getId());

        if ($request->getUserVar('save')) {
            $form->readInputData();
            if ($form->validate()) {
                $form->execute();
                return new JSONMessage(true);
            }
        } else {
            $form->initData();
        }

        return new JSONMessage(true, $form->fetch($request));
    }

    /**
     * Probe the VJOL ingest API with the credentials currently in the settings form
     * (falling back to the saved ones), so a misconfiguration surfaces here rather
     * than as a failed article delivery.
     */
    private function testConnection($request): JSONMessage
    {
        if (!$request->checkCSRF()) {
            return new JSONMessage(false, __('form.csrfInvalid'));
        }

        $context = $request->getContext();
        if (!$context) {
            return new JSONMessage(false, __('plugins.generic.nastis.error.notConfigured'));
        }

        try {
            $result = (new NastisSyncService($this))->testConnection(
                $context->getId(),
                [
                    'baseUrl' => (string) $request->getUserVar('baseUrl'),
                    'journalCode' => (string) $request->getUserVar('journalCode'),
                    'clientId' => (string) $request->getUserVar('clientId'),
                    'apiKey' => (string) $request->getUserVar('apiKey'),
                ]
            );
        } catch (\Throwable $e) {
            $result = [
                'ok' => false,
                'healthy' => false,
                'code' => 'ERROR',
                'message' => $e->getMessage(),
            ];
        }

        return new JSONMessage(true, $result);
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\\APP\\plugins\\generic\\nastis\\NastisPlugin', '\\NastisPlugin');
}
