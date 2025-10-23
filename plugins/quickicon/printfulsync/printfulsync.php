<?php
/**
 * @package     PrintfulVirtueMart
 * @subpackage  Plugin.Quickicon.Printfulsync
 *
 * @copyright   Copyright (C) 2025 Printful
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Plugin\Quickicon\Printfulsync;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;

\defined('_JEXEC') or die;

/**
 * Quick Icon plugin adding a shortcut to the Printful synchronisation screen.
 */
final class PlgQuickiconPrintfulsync extends CMSPlugin
{
    /**
     * Adds the quick icon on the administrator dashboard.
     *
     * @param string $context
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function onGetIcons(string $context): ?array
    {
        if ($context !== 'mod_quickicon') {
            return null;
        }

        $app = Factory::getApplication();

        if (!$app instanceof AdministratorApplication || !$app->isClient('administrator')) {
            return null;
        }

        $user = $app->getIdentity();

        if (!$user || !$user->authorise('core.manage', 'com_plugins')) {
            return null;
        }

        return [[
            'link'   => 'index.php?option=plg_printfulsync',
            'image'  => 'fa fa-sync',
            'text'   => Text::_('PLG_QUICKICON_PRINTFULSYNC_TITLE'),
            'id'     => 'plg_quickicon_printfulsync',
            'group'  => 'MOD_QUICKICON_EXTENSIONS',
            'access' => true,
        ]];
    }
}
