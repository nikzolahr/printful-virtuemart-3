<?php
/**
 * @package     PrintfulVirtueMart
 * @subpackage  Component.Printfulsync.Administrator
 *
 * © 2025 Printful
 */

declare(strict_types=1);

namespace Joomla\Component\Printfulsync\Administrator\View\ControlPanel;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Registry\Registry;
use Joomla\Component\Printfulsync\Administrator\Model\ControlPanelModel;

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
        $this->addTemplatePath(JPATH_COMPONENT_ADMINISTRATOR . '/tmpl/controlpanel');
        $this->addToolbar();

        return parent::display($tpl);
    }

    /**
     * Configures the Joomla toolbar for the view.
     */
    private function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_PRINTFULSYNC_TITLE_CONTROL_PANEL'), 'cog');
        ToolbarHelper::apply('controlpanel.save');
        ToolbarHelper::cancel('controlpanel.cancel');
    }
}
