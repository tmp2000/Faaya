<?php

class Cryozonic_Stripe_Block_Customer_Savedcards extends Mage_Customer_Block_Account_Dashboard
{
    public $billingInfo;

    protected function _construct()
    {
        parent::_construct();
        $this->stripe = Mage::getModel('cryozonic_stripe/standard');
        $this->form = Mage::app()->getLayout()->createBlock('payment/form_cc');
        $this->billingInfo = Mage::helper('cryozonic_stripe')->getSessionQuote()->getBillingAddress();
    }

    public function getPublishableKey()
    {
        $mode = $this->stripe->store->getConfig('payment/cryozonic_stripe/stripe_mode');
        $path = "payment/cryozonic_stripe/stripe_{$mode}_pk";
        return trim($this->stripe->store->getConfig($path));
    }

    public function getCcMonths()
    {
        return $this->form->getCcMonths();
    }

    public function getParam($str)
    {
        $newcard = $this->getRequest()->getParam('newcard', null);
        if (empty($newcard) || empty($newcard[$str])) return null;

        return $newcard[$str];
    }

    public function getCcYears()
    {
        return $this->form->getCcYears();
    }
}
