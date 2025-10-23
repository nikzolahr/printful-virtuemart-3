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
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Registry\Registry;
use Throwable;
use VmConfig;
use VmInfo;
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
     * Builds the synchronisation service with the plugin parameters.
     */
    private function createSyncService(): PrintfulSyncService
    {
        $params = $this->params instanceof Registry ? $this->params : new Registry($this->params);
        $logger = Factory::getApplication();

        return new PrintfulSyncService($params, $logger);
    }
}
