<?php

class MageWorkshop_ImportExportReview_Model_Resource_Sync_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct()
    {
        $this->_init('mageworkshop_importexportreview/sync');
    }
}