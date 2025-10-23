<?php
/**
 * @package     PrintfulVirtueMart
 * @subpackage  Plugin.System.PrintfulSync.Administrator
 *
 * @copyright   Copyright (C) 2024 Printful
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Plugin\System\Printfulsync\Administrator\View\ControlPanel;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Registry\Registry;
use Joomla\Plugin\System\Printfulsync\Administrator\Model\ControlPanelModel;

/**
 * HTML View class for the Printful Sync control panel.
 */
final class HtmlView extends BaseHtmlView
{
    /**
     * Configuration form.
     */
    protected Form $form;

    /**
     * Plugin parameters registry.
     */
    protected Registry $params;

    /**
     * Prepares the document before rendering.
     */
    public function display($tpl = null)
    {
        /** @var ControlPanelModel $model */
        $model = $this->getModel();

        $this->form   = $model->getForm();
        $this->params = $model->getParams();

        $this->setLayout('default');
        $this->addTemplatePath(__DIR__ . '/../../tmpl/controlpanel');
        $this->addToolbar();

        return parent::display($tpl);
    }

    /**
     * Configures the Joomla toolbar for the view.
     */
    private function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('PLG_SYSTEM_PRINTFULSYNC_TITLE_CONTROL_PANEL'), 'cog');
        ToolbarHelper::apply('controlpanel.save');
        ToolbarHelper::cancel('controlpanel.cancel');
    }
}
