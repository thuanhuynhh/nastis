<?php

namespace APP\plugins\generic\nastis\classes\hooks;

use APP\core\Application;
use PKP\security\Role;

class NastisMenuHandler
{
    public function addMenu($hookName, $args): bool
    {
        $templateMgr = $args[0];

        $request = Application::get()->getRequest();
        $router = $request->getRouter();
        $userRoles = (array) $router->getHandler()->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES);

        $menu = $templateMgr->getState('menu');

        if (empty($menu) || !in_array(Role::ROLE_ID_MANAGER, $userRoles, true)) {
            return false;
        }

        $offset = array_search('settings', array_keys($menu));
        $entry = [
            'name' => __('plugins.generic.nastis.navigation.nastis'),
            'url' => $router->url($request, null, 'nastis'),
            'isCurrent' => $router->getRequestedPage($request) === 'nastis',
            'icon' => 'Workflow',
        ];

        if ($offset === false || count($menu) <= $offset) {
            $menu['nastis'] = $entry;
        } else {
            $menu = array_slice($menu, 0, $offset, true)
                + ['nastis' => $entry]
                + array_slice($menu, $offset, null, true);
        }

        $templateMgr->setState(['menu' => $menu]);

        return false;
    }
}
