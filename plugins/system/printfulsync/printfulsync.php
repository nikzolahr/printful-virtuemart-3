<?php
/**
 * @package     PrintfulVirtueMart
 * @subpackage  Plugin.System.PrintfulSync
 *
 * @copyright   Copyright (C) 2024 Printful
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Plugin\System\Printfulsync;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\Registry\Registry;
use Throwable;
use VmConfig;
use VmInfo;
use Joomla\Plugin\System\Printfulsync\Administrator\Controller\ControlPanelController;
use Joomla\Plugin\System\Printfulsync\Service\PrintfulSyncService;

defined('_JEXEC') or die;

/**
 * System plugin integrating Printful product synchronisation with VirtueMart.
 */
final class PlgSystemPrintfulsync extends CMSPlugin
{
    /**
     * @var bool
     */
    protected $autoloadLanguage = true;

    /**
     * Executes a synchronisation run for the provided Printful payload.
     *
     * This method can be invoked by a CLI command, a scheduled task or any
     * other integration layer that dispatches the `onPrintfulSyncProduct`
     * event.
     *
     * @param  array<string, mixed>  $productPayload Structured product payload
     *                                               as received from the
     *                                               Printful API (product with
     *                                               nested variants).
     *
     * @return bool True when the synchronisation completed without fatal
     *              errors.
     */
    public function onPrintfulSyncProduct(array $productPayload): bool
    {
        if (!class_exists('VmConfig')) {
            return false;
        }

        try {
            VmConfig::loadConfig();

            $service = $this->createSyncService();
            $service->syncPrintfulProductToVM($productPayload);

            return true;
        } catch (Throwable $throwable) {
            $message = sprintf('Printful sync failed: %s', $throwable->getMessage());
            Log::add($message, Log::ERROR, 'plg_system_printfulsync');
            VmInfo::show($message, false, 'error');

            return false;
        }
    }

    /**
     * Renders the administrative control panel for the plugin when requested.
     */
    public function onAfterRoute(): void
    {
        $app = Factory::getApplication();

        if (!$app->isClient('administrator')) {
            return;
        }

        $input = $app->input;

        if ($input->getCmd('option') !== 'plg_printfulsync') {
            return;
        }

        $user = $app->getIdentity();

        $canManagePlugins = $user !== null
            && ($user->authorise('core.manage', 'com_plugins') || $user->authorise('core.edit', 'com_plugins'));

        if (!$canManagePlugins) {
            $app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            $app->redirect(Route::_('index.php?option=com_cpanel', false));
            $app->close();

            return;
        }

        require_once __DIR__ . '/administrator/src/Model/ControlPanelModel.php';
        require_once __DIR__ . '/administrator/src/View/ControlPanel/HtmlView.php';
        require_once __DIR__ . '/administrator/src/Controller/ControlPanelController.php';

        $controller = new ControlPanelController(
            [
                'base_path'  => __DIR__ . '/administrator',
                'model_path' => __DIR__ . '/administrator/src/Model',
                'view_path'  => __DIR__ . '/administrator/src/View',
            ],
            null,
            $app,
            $input
        );
        $controller->execute($input->getCmd('task', 'display'));
        $controller->redirect();
        $app->close();
    }

    /**
     * Builds the synchronisation service with the plugin parameters.
     */
    private function createSyncService(): PrintfulSyncService
    {
        $params = $this->params instanceof Registry ? $this->params : new Registry($this->params);
        $logger = Factory::getApplication();

        return new PrintfulSyncService($params, $logger);
    }
}
