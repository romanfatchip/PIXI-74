<?php

use Shopware\Components\CSRFWhitelistAware;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

/**
 * updated and finish transactions
 */
class Shopware_Controllers_Frontend_MoptShopNotification extends Shopware_Controllers_Frontend_Payment implements CSRFWhitelistAware
{

    protected $moptPayone__serviceBuilder = null;
    protected $moptPayone__main = null;
    protected $moptPayone__helper = null;
    protected $moptPayone__paymentHelper = null;
    /** @var Logger $logger */
    protected $logger = null;
    protected $rotatingLogger = null;
    protected $payoneConfig = null;

    /**
     * init notification controller for processing status updates
     */
    public function init()
    {
        $this->moptPayone__serviceBuilder = $this->Plugin()->Application()->MoptPayoneBuilder();
        $this->moptPayone__main = $this->Plugin()->Application()->MoptPayoneMain();
        $this->moptPayone__helper = $this->moptPayone__main->getHelper();
        $this->moptPayone__paymentHelper = $this->moptPayone__main->getPaymentHelper();

        $this->initForwardRotatingLogger();
        $this->logger = new Logger('moptPayone');
        $streamHandler = new StreamHandler(
            $this->buildPayoneTransactionLogPath(),
            Logger::DEBUG
        );
        $this->logger->pushHandler($streamHandler);
        $this->Front()->Plugins()->ViewRenderer()->setNoRender();
    }

    /**
     * Initializes rotating logger for tracing forward requests
     *
     * @param void
     * @return void
     */
    protected function initForwardRotatingLogger()
    {
        $logPath = Shopware()->Application()->Kernel()->getLogDir();
        $logFile = $logPath . '/MoptPaymentPayone_txredirect_production.log';

        $rfh = new RotatingFileHandler($logFile, 14);
        $this->rotatingLogger = new Logger('MoptPaymentPayone');
        $this->rotatingLogger->pushHandler($rfh);
    }

    /**
     * whitelists indexAction for SW 5.2 compatibility
     */
    public function getWhitelistedCSRFActions()
    {
        return [
            'index',
        ];
    }

    /**
     * index action called by Payone platform to transmit transaction status updates
     *
     * @return mixed
     */
    public function indexAction()
    {
        $request = $this->Request();

        $this->logger->debug('notification controller called');
        
        if (!$request->isPost()) {
            $this->redirect(array('controller' => 'index', 'action' => 'error'));
            return;
        }

        $rawPost = $_POST;
        $_POST = array_map('utf8_encode', $_POST); // utf8 encode all post params to avoid encoding issues
        $request->setParamSources(array('_POST')); // only retrieve data from POST

        $this->CheckAndFixActiveShopIfNeeded($request->getParam('param'));

        $transactionId = $request->getParam('txid');
        $this->logger->debug('push received for tx ' . $transactionId);
        $isOrderFinished = $this->isOrderFinished($transactionId, $request->getParam('reference'));

        if ($isOrderFinished) {
            $order = $this->loadOrderByTransactionId($transactionId);
            $paymentId = $order['paymentID'];
        } else {
            $this->restoreSession($request->getParam('param'));
            $session = Shopware()->Session();
            if (!property_exists($session, 'sOrderVariables')) {
                $message = 'The session could not be restored. It might help to configure the server\'s gc_probability:'
                    . '\n\n   https://developers.shopware.com/sysadmins-guide/sessions/#blocking-transactions'
                    . '\n   https://www.php.net/manual/de/session.configuration.php#ini.session.gc-probability';
                $this->logger->error($message);
            } elseif (!method_exists($orderVariables = $session['sOrderVariables'], 'getArrayCopy')) {
                $message = 'Method \'getArrayCopy\' does not exist, this might depend on malformed Object'
                    . 'sOrderVariables because of existence of Plugin \'SwagAdvancedCart\'. See also'
                    . '\n   https://github.com/PAYONE-GmbH/shopware-5/issues/37#issuecomment-399108861';
                $this->logger->error($message);
            }
            $orderVariables = $session['sOrderVariables']->getArrayCopy();
            $paymentId = $orderVariables['sUserData']['additional']['user']['paymentID'];
        }

        $config = $this->moptPayone__main->getPayoneConfig($paymentId, true);
        $this->payoneConfig = $config;
        Shopware()->Config()->mopt_payone__paymentId = $paymentId; //store in config for log
        $key = $config['apiKey'];

        $moptConfig = new Mopt_PayoneConfig();
        $validIps = $moptConfig->getValidIPs();

        $service = $this->moptPayoneInitTransactionService($key, $validIps);

        // enable support for proxied requests and load balancers for IP Validation
        $validators = $service->getValidators();
        foreach ($validators as $validator) {
            if ($validator instanceof Payone_TransactionStatus_Validator_Ip) {
                $validator->getConfig()->setValue('validator/proxy/enabled',1);
            }
        }

        try {
            $response = $service->handleByPost();
        } catch (Exception $exc) {
            $this->logger->error('error processing request', array($exc->getTraceAsString()));
            echo 'error processing request';
            exit;
        }

        $orderIsCorrupted = false;

        $payoneRequest = $service->getMapper()->mapByArray($request->getPost());
        $clearingData = $this->moptPayone__paymentHelper->extractClearingDataFromResponse($payoneRequest);
        if ($clearingData && !$isOrderFinished) {
            $session->offsetSet('moptClearingData', $clearingData);
        }

        if (!$isOrderFinished) {
            $orderHash = md5(serialize($session['sOrderVariables']));
            $customParam = explode('|', $request->getParam('param'));

            if ($request->getParam('txaction') !== 'failed') {
                if ($orderHash !== $customParam[2]) {
                    $this->logger->error('order corrupted - order hash mismatch');
                    $orderIsCorrupted = true;
                    $paymentStatus = 21;
                    $orderNumber = $this->saveOrder($transactionId, $request->getParam('reference'), $paymentStatus);
                } else {
                    $orderNumber = $this->saveOrder($transactionId, $request->getParam('reference'));
                }
                $order = $this->loadOrderByOrderNumber($orderNumber);
            } else {
                $this->logger->debug('finished, output TSOK');
                echo $response->getStatus();
                $this->logger->debug('starting tx forwards');
                $this->moptPayoneForwardTransactionStatus($rawPost, $paymentId);
                $this->logger->debug('finished all tasks, exit');
                exit;
            }
        }

        $attributeData = array();
        $saveOrderHash = false;
        $saveClearingData = false;
        $attributeData['mopt_payone_status'] = $request->getParam('txaction');
        $attributeData['mopt_payone_sequencenumber'] = $payoneRequest->getSequencenumber();
        $attributeData['mopt_payone_payment_reference'] = $request->getParam('reference');
        if (isset($customParam[2])) {
            $attributeData['mopt_payone_order_hash'] = $customParam[2];
            $saveOrderHash = true;
        }

        if ($clearingData && !$this->clearingDataExists($order)) {
            $attributeData['mopt_payone_clearing_data'] = json_encode($clearingData);
            $saveClearingData = true;
        }


        if (!$orderIsCorrupted) {
            $mappedShopwareState = $this->moptPayone__helper->getMappedShopwarePaymentStatusId(
                $config,
                $request->getParam('txaction')
            );

            $transaction_status = $request->getParam('transaction_status');
            $failedcause = $request->getParam('reasoncode');
            //$transaction_status = 'pending';
            //$failedcause = '-981';

            $paymentName = $this->moptPayone__paymentHelper->getPaymentNameFromId($order['paymentID']);
            if ($paymentName === 'mopt_payone__ewallet_amazon_pay') {
                if ($request->getParam('txaction') == 'failed') {
                    // save failed status with mail notification
                    $this->savePaymentStatus($transactionId, $order['temporaryID'], $mappedShopwareState, true);
                } elseif ($request->getParam('txaction') == 'appointed' && $transaction_status == 'pending' && $failedcause == '981') {
                    // InvalidPayment Method: update Order Status to "amazon_delayed" (119) and send mail notification
                    $this->savePaymentStatus($transactionId, $order['temporaryID'], 119, true);
                    $attributeData['mopt_payone_status'] = 'pending';
                } elseif ($request->getParam('txaction') == 'appointed' && $transaction_status == 'pending' && $failedcause != '981') {
                    // InvalidPayment Method: update Order Status to "amazon_delayed" (119)
                    $this->savePaymentStatus($transactionId, $order['temporaryID'], 119);
                    $attributeData['mopt_payone_status'] = 'pending';
                } else {
                    $this->savePaymentStatus($transactionId, $order['temporaryID'], $mappedShopwareState);
                }
                // !Amazonpay
            } else {
                $this->savePaymentStatus($transactionId, $order['temporaryID'], $mappedShopwareState);
            }
        }

        $this->logger->debug('save attribute data', $attributeData);
        $this->saveOrderAttributeData($order, $attributeData, $saveOrderHash, $saveClearingData);


        $this->logger->debug('finished, output TSOK');
        echo $response->getStatus();
        $this->logger->debug('starting tx forwards');
        $this->moptPayoneForwardTransactionStatus($rawPost, $paymentId);
        $this->logger->debug('finished all tasks, exit');
        exit;
    }

    /**
     * get transaction service, validate key and ip addresses
     *
     * @param string $key
     * @param array $validIps
     * @return service
     */
    protected function moptPayoneInitTransactionService($key, $validIps)
    {
        $hashedKey = md5($key);
        $service = $this->moptPayone__serviceBuilder->buildServiceTransactionStatusHandleRequest($hashedKey, $validIps);
        $service->getServiceProtocol()->addRepository(Shopware()->Models()->getRepository(
            'Shopware\CustomModels\MoptPayoneTransactionLog\MoptPayoneTransactionLog'
        ));
        return $service;
    }

    /**
     * forward request to configured urls
     *
     * @param array $rawPost
     * @param $paymentID
     */
    protected function moptPayoneForwardTransactionStatus($rawPost, $paymentID)
    {
        $url = Shopware()->Front()->Router()
            ->assemble(
                array(
                    'controller' => 'MoptTransactionStatusForwarding',
                    'action' => 'index'
                )
            );

        $rawPost['paymentID'] = $paymentID;

        $log_msg = [
            'Trying to forward to own controller: '.$url,
            'action='.$rawPost['txaction'],
            'txid='.$rawPost['txid'],
        ];
        $this->forwardLog($log_msg);

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $rawPost);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $curl_timeout = 200;
        $curl_timeout_raise = 100;
        $curl_trials_max = 3;

        if ($this->payoneConfig['transTimeout']) {
            $curl_timeout = $this->payoneConfig['transTimeout'];
        }

        if ($this->payoneConfig['transTimeoutRaise']) {
            $curl_timeout_raise = $this->payoneConfig['transTimeoutRaise'];
        }

        if ($this->payoneConfig['transMaxTrials']) {
            $curl_trials_max = $this->payoneConfig['transMaxTrials'];
        }

        $curl_trials = 0;

        do {
            $curl_trials++;

            curl_setopt($curl, CURLOPT_TIMEOUT_MS, $curl_timeout);

            $result = curl_exec($curl);

            $curl_info = curl_getinfo($curl);

            $response_code = $curl_info['http_code'];
            $curl_info_json = json_encode($curl_info);

            $log_msg = [
                'Responsecode of request was: ' . $response_code,
                'Trials: ' . $curl_trials,
                'curl_timeout: ' . $curl_timeout,
                'curl_result: ' . $result,
                'curl info: ' . $curl_info_json,
                'raw_post: ' . json_encode($rawPost),
            ];
            $this->forwardLog($log_msg);

            $curl_timeout += $curl_timeout_raise;
        } while ($curl_trials < $curl_trials_max && $response_code !== 100);

        curl_close($curl);
    }

    /**
     * Logs an entry of transaction forward controller
     *
     * @param array $logentry
     * @return void
     */
    protected function forwardLog($logentry)
    {
        $logAllowed = (
            $this->payoneConfig['transLogging'] === true
        );

        if ($logAllowed) {
            $log=implode(";",$logentry);
            $this->rotatingLogger->debug($log);
        }
    }


    /**
     * get plugin bootstrap
     *
     * @return plugin
     */
    public function Plugin()
    {
        return Shopware()->Plugins()->Frontend()->MoptPaymentPayone();
    }

    /**
     * try to load order via transaction id
     *
     * @param string $transactionId
     * @return order
     */
    protected function loadOrderByTransactionId($transactionId)
    {
        $sql = '
            SELECT id, ordernumber, paymentID, temporaryID, transactionID  FROM s_order
            WHERE transactionID=?';

        $order = Shopware()->Db()->fetchRow($sql, array($transactionId));

        return $order;
    }

    /**
     * try to load order via transaction id
     *
     * @param string $orderNumber
     * @return order
     */
    protected function loadOrderByOrderNumber($orderNumber)
    {
        $sql = '
            SELECT id, ordernumber, paymentID, temporaryID, transactionID  FROM s_order
            WHERE ordernumber=?';

        $order = Shopware()->Db()->fetchRow($sql, array($orderNumber));

        return $order;
    }

    /**
     * restore session from Id
     *
     * @param string $customParam
     */
    protected function restoreSession($customParam)
    {
        $sessionParam = explode('|', $customParam);

        \Enlight_Components_Session::writeClose();
        \Enlight_Components_Session::setId($sessionParam[1]);
        \Enlight_Components_Session::start();
    }

    /**
     * determine wether order is already finished
     *
     * @param string $transactionId
     * @param string $paymentReference
     * @return boolean
     */
    protected function isOrderFinished($transactionId, $paymentReference)
    {
        $sql = '
            SELECT ordernumber FROM s_order
            WHERE transactionID=? AND status!=-1';

        $orderNumber = Shopware()->Db()->fetchOne($sql, array($transactionId));

        if (empty($orderNumber) && !$this->isFinishedWithReference($paymentReference, $transactionId)) {
            return false;
        }

        return true;
    }

    /**
     * determine wether order is already finished
     * additional check for frontend api creditcard payments
     *
     * @param string $paymentReference
     * @param string $transactionId
     * @return boolean
     */
    protected function isFinishedWithReference($paymentReference, $transactionId)
    {
        $sql = '
            SELECT ordernumber FROM s_order
            WHERE transactionID=? AND status!=-1';

        $orderNumber = Shopware()->Db()->fetchOne($sql, array($paymentReference));

        if (empty($orderNumber)) {
            return false;
        }

        $this->setTransactionId($orderNumber, $transactionId);
        return true;
    }

    /**
     * update transaction id, needed for frontend api creditcard payments
     *
     * @param string $orderNumber
     * @param string $transactionId
     * @return boolean
     */
    protected function setTransactionId($orderNumber, $transactionId)
    {
        $sql = '
            UPDATE s_order SET transactionID=?
            WHERE ordernumber=?';

        Shopware()->Db()->query($sql, array($transactionId, $orderNumber));
    }

    /**
     * persist tx-status information
     *
     * @param array $order
     * @param array $attributeData
     * @param bool $saveOrderHash
     * @param bool $saveClearingData
     */
    protected function saveOrderAttributeData($order, $attributeData, $saveOrderHash, $saveClearingData)
    {
        $params = array(
            $attributeData['mopt_payone_status'],
            $attributeData['mopt_payone_sequencenumber'],
            $attributeData['mopt_payone_payment_reference'],
        );

        $sql = 'UPDATE s_order_attributes SET mopt_payone_status=?, mopt_payone_sequencenumber=?, '
                . 'mopt_payone_payment_reference=? ';

        if ($saveOrderHash) {
            $sql = $sql . ' , mopt_payone_order_hash=? ';
            $params[] = $attributeData['mopt_payone_order_hash'];
        }

        if ($saveClearingData) {
            $sql = $sql . ' , mopt_payone_clearing_data=? ';
            $params[] = $attributeData['mopt_payone_clearing_data'];
        }
        $sql = $sql . ' WHERE orderID=?';
        $params[] = $order['id'];

        Shopware()->Db()->query($sql, $params);
    }

    /**
     * check if correct active shop is loaded
     * load and register correct shop and resources
     *
     * @param array $customParam
     */
    protected function CheckAndFixActiveShopIfNeeded($customParam)
    {
        $sessionParam = explode('|', $customParam);

        $cookieName = explode('-', $sessionParam[0]);
        $shopId = (int)$cookieName[1];
        $activeShopId = Shopware()->Shop()->getId();

        if ($activeShopId !== $shopId) {
            $shopRepository = Shopware()->Models()->getRepository('Shopware\Models\Shop\Shop');
            $shop = $shopRepository->getActiveById($shopId);
            $shop->registerResources(Shopware()->Bootstrap());
            
            $this->logger->info(
                'different shop active, submitted id, new shopid',
                array($activeShopId, $shopId, Shopware()->Shop()->getId())
            );
        }
    }

    /**
     * check if clearingData already exists in DB
     *
     * @param array $order
     * @return boolean
     */
    protected function clearingDataExists($order)
    {
        $sql = 'SELECT mopt_payone_clearing_data FROM s_order_attributes WHERE orderID=?';
        $params[] = $order['id'];

        $clearingData = Shopware()->Db()->query($sql, $params);

        if (empty($clearingData)) {
            $this->logger->debug('clearingdata is empty');
            return false;
        }

        $this->logger->debug('clearingdata already exists');
        return true;
    }

    /**
     * builds the transaction log path
     * @return string
     */
    protected function buildPayoneTransactionLogPath()
    {
        $logDir = Shopware()->Application()->Kernel()->getLogDir();
        return  $logDir . '/moptPayoneTransactionStatus.log';
    }
}
