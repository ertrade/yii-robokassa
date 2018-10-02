<?php

class Robokassa extends CApplicationComponent
{
    const CHECK_PASS1 = 1;
    const CHECK_PASS2 = 2;

    public $sMerchantLogin;
    public $sMerchantPass1;
    public $sMerchantPass2;
    public $sCulture = 'ru';

    public $resultMethod = 'post';
    public $sIncCurrLabel = 'QiwiR';
    public $orderModel;
    public $priceField;
    public $isTest = false;

    public $params;

    protected $_order;

    public function pay($nOutSum, $nInvId, $sInvDesc, $sUserEmail, $extraParams = array())
    {
        ksort($extraParams);
        $sShpParams = array();
        foreach ($extraParams as $key => $value) {
            $sShpParams['Shp_'.$key] = urlencode($value);
        }

        $sign = $this->getPaySign($nOutSum, $nInvId, $sShpParams);

        $url = 'https://merchant.roboxchange.com/Index.aspx?';

        $params = array(
            "MrchLogin={$this->sMerchantLogin}",
            "OutSum={$nOutSum}",
            "InvId={$nInvId}",
            "Desc={$sInvDesc}",
            "SignatureValue={$sign}",
            "IncCurrLabel={$this->sIncCurrLabel}",
            "Email={$sUserEmail}",
            "Culture={$this->sCulture}"
        );
        if ($this->isTest) {
            array_push($params, 'IsTest=1');
        }
        foreach ($sShpParams as $key => $value) {
            array_push($params, $key.'='.urlencode($value));
        }
        $url .= implode('&', $params);

        Yii::app()->controller->redirect($url);
    }

    private function getPaySign($nOutSum, $nInvId, $sShpParams)
    {
        $keys = array(
            $this->sMerchantLogin,
            $nOutSum,
            $nInvId,
            $this->sMerchantPass1,
        );
        foreach ($sShpParams as $key => $value) {
            array_push($keys, $key.'='.$value);
        }
        return md5(implode(':', $keys));
    }

    public function result()
    {
        $http = $_GET + $_POST;

        $ShpParams = array_filter($http, function($key) {
            return strncmp($key, 'Shp_', 4) === 0;
        }, ARRAY_FILTER_USE_KEY);

        $valid = true;

        if (isset($http['OutSum'], $http['InvId'], $http['SignatureValue'])) {
            $OutSum = $http['OutSum'];
            $InvId = $http['InvId'];
            $SignatureValue = $http['SignatureValue'];
        } else {
            $this->params = array('reason' => 'Dont set need value');
            $valid = false;
        }

        if (!$valid || !$this->checkResultSignature($OutSum, $InvId, $SignatureValue, $ShpParams)) {
            $this->params = array('reason' => 'Signature fail');
            $valid = false;
        }

        if (!$valid || !$this->isOrderExists($InvId)) {
            $this->params = array('reason' => 'Order not exists');
            $valid = false;
        }

        if (!$valid || $this->_order->{$this->priceField} != $OutSum) {
            $this->params = array('reason' => 'Order price error');
            $valid = false;
        }

        $event = new CEvent($this);
        if ($valid) {
            if ($this->hasEventHandler('onSuccess')) {
                $this->params = array('order' => $this->_order);
                $this->onSuccess($event);
            }
        } else {
            if ($this->hasEventHandler('onFail')) {
                return $this->onFail($event);
            }
        }

        echo "OK{$InvId}\n";
    }

    private function isOrderExists($id)
    {
        $this->_order = CActiveRecord::model($this->orderModel)->findByPk((int)$id);

        if ($this->_order)
            return true;

        return false;
    }

    public function checkResultSignature($OutSum, $InvId, $SignatureValue, $ShpParams = array(), $checkType = self::CHECK_PASS2)
    {
        $keys = array(
            $OutSum,
            $InvId,
            $checkType == self::CHECK_PASS1 ? $this->sMerchantPass1 : $this->sMerchantPass2,
        );
        ksort($ShpParams);
        foreach ($ShpParams as $key => $value) {
            array_push($keys, $key.'='.$value);
        }

        $sign = strtoupper(md5(implode(':', $keys)));

        return strtoupper($SignatureValue) == $sign;
    }

    public function onSuccess($event)
    {
        $this->raiseEvent('onSuccess', $event);
    }

    public function onFail($event)
    {
        $this->raiseEvent('onFail', $event);
    }
}
