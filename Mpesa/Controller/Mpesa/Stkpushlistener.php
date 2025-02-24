<?php
/**
 * Created by PhpStorm.
 * User: user
 * Date: 2/14/2018
 * Time: 7:11 PM
 */
namespace Safaricom\Mpesa\Controller\Mpesa;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;

class Stkpushlistener extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
{

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Safaricom\Mpesa\Model\Mpesac2b $mpesa,
        \Safaricom\Mpesa\Helper\Data $helper,
        \Safaricom\Mpesa\Model\StkpushFactory $stkpush
    )
    {
        $this->_stkpush = $stkpush;
        $this->_mpesa = $mpesa;
        $this->helper = $helper;
        parent::__construct($context);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }



    public function execute()
    {

        $data = file_get_contents('php://input');

        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/stkpush.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $splunk = (string)$data;
        $logger->info('stkpush:- ' . $splunk);

        $data = json_decode($data,true);
        $ResultCode = $data["Body"]["stkCallback"]["ResultCode"];
        $ResultDesc = $data["Body"]["stkCallback"]["ResultDesc"];
        $MerchantRequestID = $data["Body"]["stkCallback"]["MerchantRequestID"];
        $CheckoutRequestID = $data["Body"]["stkCallback"]["CheckoutRequestID"];
        
        $mpesa = $this->_stkpush->create()->load($MerchantRequestID,'merchant_request_id');
        $mpesa->setResultCode($ResultCode);
        $mpesa->setResultDesc($ResultDesc);
        $mpesa->save();

        if($ResultCode == 0)
        {
            $items = $data["Body"]["stkCallback"]["CallbackMetadata"]['Item'];

            if(is_array($items))
            {
                $receipt = $amount = $Balance = $TransactionDate = $PhoneNumber = null;
                foreach($items as $item)
                {
                    $item['Name'] == 'Amount' ? isset($item['Value']) ? $amount = $item['Value'] : '' : '';
                    $item['Name'] == 'MpesaReceiptNumber' ? isset($item['Value']) ? $receipt = $item['Value'] : '' : '';
                    $item['Name'] == 'Balance' ? isset($item['Value']) ? $Balance = $item['Value'] : '' : '';
                    $item['Name'] == 'TransactionDate' ? isset($item['Value']) ? $TransactionDate = $item['Value'] : '' : '';
                    $item['Name'] == 'PhoneNumber' ? isset($item['Value']) ? $PhoneNumber = $item['Value'] : '' : '';

                }
                $data = ['trans_id'=>$receipt,'callback_time'=>$TransactionDate,'trans_amount'=>$amount,
                    'msisdn'=>$PhoneNumber,'invoice_number'=>$mpesa->getStkId(),'trans_time'=>$TransactionDate,
                    'business_shortcode'=> $this->helper->getGeneralConfig('my_paybill'), 'bill_ref_number'=>$mpesa->getAccountId()];
                $this->_mpesa->setData($data)->save();
            }

            echo json_encode([
                'data' => $data,
                'success' => true,
                'ResultCode' => $ResultCode,
                'MerchantRequestID' => $MerchantRequestID,
                'CheckoutRequestID' => $CheckoutRequestID
            ]);
        } else 
        {
            echo json_encode([
                'data' => $data,
                'success' => false,
                'ResultCode' => $ResultCode,
                'MerchantRequestID' => $MerchantRequestID,
                'CheckoutRequestID' => $CheckoutRequestID
            ]);
        }

    }
}

