<?php

namespace APP\plugins\generic\nastis\classes\hooks;

use APP\plugins\generic\nastis\classes\handlers\pages\NastisHandler;
use PKP\plugins\GenericPlugin;

class NastisPageHandler
{
    public function __construct(private GenericPlugin $plugin)
    {
    }

    public function addHandlers($hookName, $args): bool
    {
        $page = $args[0];
        $op = $args[1];
        $handler = &$args[3];

        if (!$this->plugin->getEnabled() || $page !== 'nastis') {
            return false;
        }

        if (in_array($op, ['index', 'search', 'sync'], true)) {
            $handler = new NastisHandler();
            return true;
        }

        return false;
    }
}
