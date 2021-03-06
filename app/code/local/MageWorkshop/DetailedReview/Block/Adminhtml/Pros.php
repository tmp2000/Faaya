<?php
/**
 * MageWorkshop
 * Copyright (C) 2016 MageWorkshop <info@mage-review.com>
 *
 * @category   MageWorkshop
 * @package    MageWorkshop_DetailedReview
 * @copyright  Copyright (c) 2016 MageWorkshop Co. (https://mage-review.com/)
 * @license    http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPL-3.0)
 * @author     MageWorkshop <info@mage-review.com>
 */
class MageWorkshop_DetailedReview_Block_Adminhtml_Pros extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    /**
     * @inherit
     */
    public function __construct()
    {
        $this->_controller = 'adminhtml_pros';
        $this->_blockGroup = 'detailedreview';
        $this->_headerText = $this->__('Pros Management');
        $this->_addButtonLabel = $this->__('Add Pros');
        parent::__construct();
    }
}
