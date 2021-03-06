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
class MageWorkshop_DetailedReview_Block_Adminhtml_Statistics_Tab_Activity extends Mage_Adminhtml_Block_Dashboard_Graph
{

    private $index = 0;

    /**
     * @inherit
     */
    public function __construct()
    {
        $this->setHtmlId('activity');
        parent::__construct();
        $this->setTemplate('detailedreview/graph.phtml');
    }

    /**
     * @param int $width
     */
    public function setWidth($width)
    {
        $this->_width = $width;
    }

    /**
     * @inherit
     */
    protected function _prepareData()
    {
        $this->setDataHelperName('detailedreview/adminhtml_statistics_activity');
        /** @var MageWorkshop_DetailedReview_Helper_Adminhtml_Statistics_Activity $dataHelper */
        $dataHelper = $this->getDataHelper();
        $dataHelper->setParam('store', $this->getRequest()->getParam('store'));
        $dataHelper->setParam('website', $this->getRequest()->getParam('website'));
        $dataHelper->setParam('group', $this->getRequest()->getParam('group'));

        $this->setDataRows('quantity');
        $this->_axisMaps = array(
            'x' => 'range',
            'y' => 'quantity'
        );

        parent::_prepareData();
    }

    /**
     * @return array
     */
    public function getDataForRows()
    {
        $this->_allSeries = $this->getRowsData($this->_dataRows);

        foreach ($this->_axisMaps as $axis => $attr) {
            $this->setAxisLabels($axis, $this->getRowsData($attr, true));
        }

        $timezoneLocal = Mage::app()->getLocale()->getTimezone();
        // $timezoneLocal = Mage::app()->getStore()->getConfig(Mage_Core_Model_Locale::XML_PATH_DEFAULT_TIMEZONE);

        /** @var MageWorkshop_DetailedReview_Helper_Adminhtml_Statistics_Activity $dataHelper */
        $dataHelper = $this->getDataHelper();
        list($dateStart, $dateEnd) = Mage::getResourceModel('reports/order_collection')
            ->getDateRange($dataHelper->getParam('period'), '', '', true);

        /** @var Zend_Date $dateStart */
        $dateStart->setTimezone($timezoneLocal);
        /** @var Zend_Date $dateEnd */
        $dateEnd->setTimezone($timezoneLocal);

        $dates = array();
        $data = array();

        while ($dateStart->compare($dateEnd) < 0) {
            switch ($dataHelper->getParam('period')) {
                case '7d':
                case '1m':
                    $date = $dateStart->toString('yyyy-MM-dd');
                    $dateStart->addDay(1);
                    break;
                case '1y':
                case '2y':
                    $date = $dateStart->toString('yyyy-MM');
                    $dateStart->addMonth(1);
                    break;
                default: // '24h'
                    $date = $dateStart->toString('yyyy-MM-dd HH:00');
                    $dateStart->addHour(1);
                    break;
            }

            $allSeries = $this->getAllSeries();
            foreach ($allSeries as $series) {
                if (in_array($date, $this->_axisLabels['x'])) {

                    $data[] = $series[$this->index];
                    $this->index++;
                } else {
                    $data[] = 0;
                }
            }
            $dates[] = $date;
        }

        $result = array();
        foreach ($dates as $index => $date) {
            if ($date != '') {
                switch ($dataHelper->getParam('period')) {
                    case '7d':
                    case '1m':
                    $result[$index]['date'] = $this->formatDate(
                            new Zend_Date($date, 'yyyy-MM-dd')
                        );
                        break;
                    case '1y':
                    case '2y':
                        $formats = Mage::app()->getLocale()->getTranslationList('datetime');
                        $format = isset($formats['yyMM']) ? $formats['yyMM'] : 'MM/yyyy';
                        $format = str_replace(array("yyyy", "yy", "MM"), array("Y", "y", "m"), $format);
                        $result[$index]['date'] = date($format, strtotime($date));
                        break;
                    default: // '24h'
                        $result[$index]['date'] = $this->formatTime(
                            new Zend_Date($date, 'yyyy-MM-dd HH:00'), 'short', false
                        );
                        break;
                }
            } else {
                $result[$index]['date'] = '';
            }
            $result[$index]['data'] = $data[$index];
        }
        return $result;
    }
}
