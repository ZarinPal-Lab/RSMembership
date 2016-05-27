<?php
/**
* @version 1.0.0
* @dev By ZarinPal
*
* @copyright (C) 2012 www.zarinpal.com
* @license GPL, http://www.gnu.org/licenses/gpl-2.0.html
*/
defined('_JEXEC') or die('Restricted access');

jimport('joomla.plugin.plugin');
jimport('joomla.html.parameter');

if (file_exists(JPATH_ADMINISTRATOR.DS.'components'.DS.'com_rsmembership'.DS.'helpers'.DS.'rsmembership.php')) {
    require_once JPATH_ADMINISTRATOR.DS.'components'.DS.'com_rsmembership'.DS.'helpers'.DS.'rsmembership.php';
}

class plgSystemRSMembershipzarinpal extends JPlugin
{
    public $_db;

    public function canRun()
    {
        return file_exists(JPATH_ADMINISTRATOR.DS.'components'.DS.'com_rsmembership'.DS.'helpers'.DS.'rsmembership.php');
    }

    public function plgSystemRSMembershipzarinpal(&$subject, $config)
    {
        parent::__construct($subject, $config);
        $this->_plugin = &JPluginHelper::getPlugin('system', 'rsmembershipzarinpal');
        $this->_params = new JParameter($this->_plugin->params);

        if (!$this->canRun()) {
            return;
        }
        RSMembership::addPlugin('درگاه پرداخت زرين پال', 'rsmembershipzarinpal');

        $this->_db = JFactory::getDBO();
    }

    public function onMembershipPayment($plugin, $data, $extra, $membership, $transaction)
    {
        if (!$this->canRun()) {
            return;
        }
        if ($plugin != $this->_plugin->name) {
            return false;
        }

        $client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']);
        $result = $client->PaymentRequest([
            'MerchantID'     => $this->_params->get('merchantID'),
            'Amount'         => intval($transaction->price),
            'Description'    => 'پرداخت جهت عضويت در سايت',
            'Email'          => '',
            'Mobile'         => '',
            'CallbackURL'    => JRoute::_(JURI::root().'index.php?option=com_rsmembership&zarinpalpayment=1'),
        ]);

        $html = '';
        $html .= '<p>در حال اتصال به درگاه پرداخت زرين پال ، لطفا منتظر بمانيد ...</p>';

        if ($result->Status == 100) {
            $transaction->custom = $result->Authority;
            $html .= 'در صورت عدم انتقال <a href="https://www.zarinpal.com/pg/StartPay/'.$result->Authority.'">اينجا</a> کليک کنيد.';
            $html .= '<script type="text/javascript">';
            $html .= 'window.location="https://www.zarinpal.com/pg/StartPay/'.$result->Authority.'";';
            $html .= '</script>';
        } else {
            $html .= 'مشکلی در اتصال رخ داده است. کد خطا: '.$result->Status;
        }

        return $html;
    }

    public function onAfterRender()
    {
        global $mainframe;

        $app = &JFactory::getApplication();
        if ($app->getName() != 'site') {
            return;
        }

        $zarinpalpayment = JRequest::getVar('zarinpalpayment', '');
        if (!empty($zarinpalpayment)) {
            $this->onPaymentNotification();
        }
    }

    public function onPaymentNotification()
    {
        if (!$this->canRun()) {
            return;
        }

        $authority = JRequest::getVar('Authority', '');

        if (JRequest::getVar('Status', '') == 'OK') {
            $this->_db->setQuery("SELECT * FROM #__rsmembership_transactions WHERE `custom`='".$this->_db->getEscaped($authority)."' AND `status`!='completed' AND `gateway`='درگاه پرداخت زرين پال'");
            $transaction = $this->_db->loadObject();

            // transaction exists
            if (empty($transaction)) {
            }

            $client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']);
            $result = $client->PaymentVerification([
                'MerchantID'     => $this->_params->get('merchantID'),
                'Authority'      => $authority,
                'Amount'         => intval($transaction->price),
            ]);

            if ($result->Status == 100) {
                RSMembership::approve($transaction->id);
                $this->_db->setQuery("UPDATE #__rsmembership_transactions SET `hash`='".$result->RefID."' WHERE `id`='".$transaction->id."' LIMIT 1");
                $this->_db->query();

                $link = $redirect = JURI::base().'index.php?option=com_rsmembership&task=thankyou';
                $app = JFactory::getApplication();
                $app->redirect($link);

                return;
            } else {
                $link = 'index.php?option=com_rsmembership&message=1';
                $msg = 'خطا در پردازش عمليات پرداخت! کد خطا: '.$result->Status;
            }
        } else {
            $link = 'index.php?option=com_rsmembership&message=1';
            $msg = 'خطا در پردازش عمليات پرداخت ، پرداخت ناموفق !';
        }

        $app = JFactory::getApplication();
        $app->redirect($link, $msg, 'notice', false);
    }
}
