<?php

namespace APP\plugins\generic\nastis;

use APP\plugins\generic\nastis\classes\services\NastisApiClient;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\Crypt;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorUrl;

class NastisSettingsForm extends Form
{
    private const SETTINGS = [
        'baseUrl',
        'journalCode',
        'clientId',
        'apiKey',
        'autoSyncOnPublish',
        'autoSyncOnEdit',
        'uploadPdf',
    ];

    public function __construct(
        private NastisPlugin $plugin,
        private int $contextId
    ) {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));

        foreach (['baseUrl', 'journalCode', 'clientId', 'apiKey'] as $field) {
            $this->addCheck(new FormValidatorCustom(
                $this,
                $field,
                'required',
                'plugins.generic.nastis.settings.fieldRequired',
                fn ($value) => !empty(trim((string) $value))
            ));
        }

        $this->addCheck(new FormValidatorUrl(
            $this,
            'baseUrl',
            'required',
            'plugins.generic.nastis.settings.baseUrl.invalid'
        ));
    }

    public function initData(): void
    {
        foreach (self::SETTINGS as $setting) {
            $value = $this->plugin->getSetting($this->contextId, $setting);
            if ($setting === 'apiKey' && $value) {
                // Tolerates keys stored before encryption was introduced; a raw
                // Crypt::decrypt() would throw and break the settings form.
                $value = NastisApiClient::revealApiKey($value);
            }
            $this->setData($setting, $value);
        }

        $this->setData('autoSyncOnPublish', (bool) $this->getData('autoSyncOnPublish'));
        $this->setData('autoSyncOnEdit', (bool) $this->getData('autoSyncOnEdit'));
        $this->setData('uploadPdf', $this->getData('uploadPdf') !== '0');
    }

    public function readInputData(): void
    {
        $this->readUserVars(self::SETTINGS);
    }

    public function fetch($request, $template = null, $display = false): string
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('pluginName', $this->plugin->getName());
        return parent::fetch($request, $template, $display);
    }

    public function execute(...$functionArgs): void
    {
        $this->setData('apiKey', Crypt::encrypt(trim((string) $this->getData('apiKey'))));

        foreach (self::SETTINGS as $setting) {
            $value = $this->getData($setting);
            if (in_array($setting, ['autoSyncOnPublish', 'autoSyncOnEdit', 'uploadPdf'], true)) {
                $this->plugin->updateSetting($this->contextId, $setting, $value ? '1' : '0', 'string');
                continue;
            }

            $this->plugin->updateSetting($this->contextId, $setting, trim((string) $value), 'string');
        }

        parent::execute(...$functionArgs);
    }
}
