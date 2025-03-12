<?php

namespace Dolphin\CartReminder\Cron;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;

class SendReminders
{
    protected $quoteCollectionFactory;
    protected $date;
    protected $transportBuilder;
    protected $storeManager;
    protected $scopeConfig;

    public function __construct(
        CollectionFactory $quoteCollectionFactory,
        DateTime $date,
        TransportBuilder $transportBuilder,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
    ) {
        $this->quoteCollectionFactory = $quoteCollectionFactory;
        $this->date = $date;
        $this->scopeConfig = $scopeConfig;
        $this->transportBuilder = $transportBuilder;
        $this->storeManager = $storeManager;
    }

    public function execute()
    {   
        $fiveMinutesAgo = $this->date->gmtDate('Y-m-d H:i:s', 
            $this->date->gmtTimestamp() - (2 * 60)); 

        $collection = $this->quoteCollectionFactory->create()
            ->addFieldToFilter('email_sent', 0) 
            ->addFieldToFilter('updated_at', ['lteq' => $fiveMinutesAgo]);

        foreach ($collection as $quote) {
            if ($quote->getCustomerEmail()) {
                $mail = $this->sendEmail($quote);
                
                if ($mail) {
                    $quote->setEmailSent(1)->save();
                }
            }
        }
    }

    protected function sendEmail($quote)
    {
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/testlog.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        
        try {
            $logger->info('Starting email send process');

            $storeId = $quote->getStoreId();
            $templateId = 'cart_reminder_email_template';

            $cartItems = $this->getCartItems($quote);

            $templateVars = [
                'customer_name' => $quote->getCustomerFirstname(),
                'cart_items' => $cartItems,
                'store_url' => $this->storeManager->getStore()->getBaseUrl(),
                'total_amount' => $cartItems['total']
            ];

            $senderEmail = $this->scopeConfig->getValue(
                'smtp/configuration_option/username',
                ScopeInterface::SCOPE_STORE
            );

            $transport = $this->transportBuilder
                ->setTemplateIdentifier($templateId)
                ->setTemplateOptions([
                    'area'  => 'frontend',
                    'store' => $storeId
                ])
                ->setTemplateVars($templateVars)
                ->setFrom([
                    'email' => $senderEmail,
                    'name'  => 'Sales Department'
                ])
                ->addTo($quote->getCustomerEmail())
                ->getTransport();
            
            $transport->sendMessage();
            $logger->info('Email sent successfully');

            return true;
        } catch (\Exception $e) {
            $logger->error('Failed to send email: ' . $e->getMessage());
            $logger->error('Stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }

    protected function getCartItems($quote)
    {
        $items = $quote->getAllVisibleItems();
        $cartItems = [
            'items' => [],
            'total' => 0
        ];

        if (!$items) {
            return $cartItems;
        }

        foreach ($items as $item) {
            $itemSubtotal = $item->getPrice() * $item->getQty();
            $cartItems['total'] += $itemSubtotal;

            $cartItems['items'][] = [
                'name' => $item->getName(),
                'sku' => $item->getSku(),
                'price' => $item->getPrice(),
                'quantity' => (int)$item->getQty(),
                'subtotal' => $itemSubtotal
            ];
        }

        return $cartItems;
    }
}



