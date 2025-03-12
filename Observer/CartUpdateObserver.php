<?php
namespace Dolphin\CartReminder\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Quote\Model\Quote;

class CartUpdateObserver implements ObserverInterface
{
    private $quoteRepository;

    public function __construct(
        \Magento\Quote\Model\QuoteRepository $quoteRepository
    ) {
        $this->quoteRepository = $quoteRepository;
    }

    public function execute(Observer $observer)
    {

        $cart = $observer->getEvent()->getCart();
        $quote = $cart->getQuote();

        if (!$quote->getCustomerEmail()) {
            return;
        }

        $hasItems = (bool)$quote->getItemsCount();
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/testlog.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);

        try {
            if (!$hasItems && $quote->getEmailSent() == 0) {
                $quote->setEmailSent(1);
                $logger->info('Cart empty - Email sent status changed to 1');
            } elseif ($hasItems && $quote->getEmailSent() == 1) {
                $quote->setEmailSent(0);
                $logger->info('Items in cart - Email sent status changed to 0');
            }

            if ($quote->hasDataChanges()) {
                $this->quoteRepository->save($quote);
            }
        } catch (\Exception $e) {
            $logger->info('Error processing cart update: ' . $e->getMessage());
        }
    }
}