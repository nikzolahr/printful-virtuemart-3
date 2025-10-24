<?php
/**
 * @package   plg_vmextended_printful
 * @copyright 2024
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;

/**
 * Renders the Printful manual sync button.
 */
class JFormFieldPrintfulSync extends FormField
{
    /**
     * @var string
     */
    protected $type = 'PrintfulSync';

    /**
     * Method to get the field input markup.
     *
     * @return string
     */
    public function getInput()
    {
        $doc = Factory::getApplication()->getDocument();
        $wa  = $doc->getWebAssetManager();

        $wa->registerAndUseScript(
            'plg_vmextended_printful.admin-sync',
            'media/plg_vmextended_printful/js/admin-sync.js',
            [],
            ['defer' => true, 'version' => 'auto']
        );

        $doc->addScriptOptions('plgVmextPrintful', [
            'tokenKey' => Session::getFormToken(),
            'ajaxUrl'  => 'index.php?option=com_ajax&plugin=printful&group=vmextended&format=raw&task=syncProducts'
        ]);

        return $this->renderMarkup();
    }

    /**
     * Build the HTML markup for the sync button and output area.
     *
     * @return string
     */
    private function renderMarkup(): string
    {
        $html  = '<div class="pf-sync-field">';
        $html .= '<button type="button" class="btn btn-primary" id="pf-sync-btn">'
            . Text::_('PLG_VMEXTENDED_PRINTFUL_SYNC_BUTTON_LABEL')
            . '</button>';
        $html .= '<div id="pf-sync-output" class="small text-muted mt-2" style="white-space:pre-wrap;"></div>';
        $html .= '</div>';

        return $html;
    }
}
