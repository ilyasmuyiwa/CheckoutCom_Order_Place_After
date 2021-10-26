<?php

/**
 * Checkout.com
 * Authorized and regulated as an electronic money institution
 * by the UK Financial Conduct Authority (FCA) under number 900816.
 *
 * PHP version 7
 *
 * @category  Magento2
 * @package   Checkout.com
 * @author    Platforms Development Team <platforms@checkout.com>
 * @copyright 2010-2019 Checkout.com
 * @license   https://opensource.org/licenses/mit-license.html MIT License
 * @link      https://docs.checkout.com/
 */

namespace CheckoutCom\Magento2\Controller\Payment;

/**
 * Class Verify
 */
class Verify extends \Magento\Framework\App\Action\Action
{
    /**
     * @var ManagerInterface
     */
    public $messageManager;

    /**
     * @var TransactionHandlerService
     */
    public $transactionHandler;

    /**
     * @var StoreManagerInterface
     */
    public $storeManager;

    /**
     * @var CheckoutApi
     */
    public $apiHandler;

    /**
     * @var OrderHandlerService
     */
    public $orderHandler;

    /**
     * @var QuoteHandlerService
     */
    public $quoteHandler;

    /**
     * @var VaultHandlerService
     */
    public $vaultHandler;

    /**
     * @var Utilities
     */
    public $utilities;

    /**
     * @var Logger
     */
    public $logger;

    /**
     * @var Session
     */
    protected $session;

    /**
     * Verify constructor
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \CheckoutCom\Magento2\Model\Service\TransactionHandlerService $transactionHandler,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \CheckoutCom\Magento2\Model\Service\ApiHandlerService $apiHandler,
        \CheckoutCom\Magento2\Model\Service\OrderHandlerService $orderHandler,
        \CheckoutCom\Magento2\Model\Service\QuoteHandlerService $quoteHandler,
        \CheckoutCom\Magento2\Model\Service\VaultHandlerService $vaultHandler,
        \CheckoutCom\Magento2\Helper\Utilities $utilities,
        \CheckoutCom\Magento2\Helper\Logger $logger,
        \Magento\Checkout\Model\Session $session
    ) {
        parent::__construct($context);

        $this->messageManager = $messageManager;
        $this->storeManager = $storeManager;
        $this->apiHandler = $apiHandler;
        $this->orderHandler = $orderHandler;
        $this->quoteHandler = $quoteHandler;
        $this->vaultHandler = $vaultHandler;
        $this->utilities = $utilities;
        $this->logger = $logger;
        $this->session = $session;
        $this->transactionHandler = $transactionHandler;
    }

    /**
     * Handles the controller method.
     */
    public function execute()
    {
        // Set some required properties
      
        // Return to the cart
        try {
            // Get the session id
            $sessionId = $this->getRequest()->getParam('cko-session-id', null);
            if ($sessionId) {
                // Get the store code
                $storeCode = $this->storeManager->getStore()->getCode();

                // Initialize the API handler
                $api = $this->apiHandler->init($storeCode);

                // Get the payment details
                $response = $api->getPaymentDetails($sessionId);
               // print_r($response);


                // Check for zero dollar auth
                if ($response->status !== "Card Verified") {


                    $data = [
                        "methodId" => $response->metadata['methodId'],
                        "cardBin" => $response->source["bin"],
                        "saveCard" => false,
                        "source" =>$response->metadata['methodId']
                    ];

                    // Set the method ID
                    $this->methodId = $response->metadata['methodId'];


                    // Find the order from increment id
                   /* $order = $this->orderHandler->getOrder([
                        'increment_id' => $response->reference
                    ]);*/
                   //Load quote.
                    $jsonQuoteData = $response->metadata['quoteData'];
                    $jsonQuoteData = json_decode($jsonQuoteData);
                    $quote = $this->quoteHandler->getQuote(["entity_id" =>$jsonQuoteData->quote_id]);


                    // Process the order
                    if ($this->orderHandler->isOrder($quote) || "a"=="a") {
                        // Logging
                        $this->logger->display($response);

                        // Process the response
                        if ($api->isValidResponse($response)) {
                            //Loyalty code for 3d

                            $order = $this->orderHandler
                                ->setMethodId($data['methodId'])
                                ->handleOrder($quote);
                            $order = $this->utilities->setPaymentData($order, $response, $data);
                            $order->save();


                            if ($response->source['type'] === 'knet') {

                                $amount = $this->transactionHandler->amountFromGateway(
                                    $response->amount ?? null,
                                    $order
                                );

                                $this->messageManager->addComplexNoticeMessage(
                                    'knetInfoMessage',
                                    [
                                        'postDate' => $response->source['post_date'] ?? null,
                                        'amount' => $amount ?? null,
                                        'paymentId' => $response->source['knet_payment_id'] ?? null,
                                        'transactionId' => $response->source['knet_transaction_id'] ?? null,
                                        'authCode' => $response->source['auth_code'] ?? null,
                                        'reference' => $response->source['bank_reference'] ?? null,
                                        'resultCode' => $response->source['knet_result'] ?? null,
                                    ]
                                );
                            }

                            if (isset($response->metadata['successUrl']) &&
                                !str_contains($response->metadata['successUrl'], 'checkout_com/payment/verify')) {
                                return $this->_redirect($response->metadata['successUrl']);
                            } else {
                                return $this->_redirect('checkout/onepage/success', ['_secure' => true]);
                            }
                        } else {
                            // Restore the quote
                            $this->session->restoreQuote();

                            // Add and error message
                            $this->messageManager->addErrorMessage(
                                __('The transaction could not be processed or has been cancelled.')
                            );
                        }
                    } else {
                        // Add an error message
                        $this->messageManager->addErrorMessage(
                            __('Invalid request. No order found.')
                        );
                    }
                } else {
                    // Save the card
                    $this->saveCard($response);

                    // Redirect to the account
                    return $this->_redirect('vault/cards/listaction', ['_secure' => true]);
                }
            } else {
                // Add and error message
                $this->messageManager->addErrorMessage(
                    __('Invalid request. No session ID found.')
                );
            }
        } catch (\Checkout\Library\Exceptions\CheckoutHttpException $e) {
            $this->messageManager->addErrorMessage(
                __($e->getBody())
            );
            return $this->_redirect('checkout/cart', ['_secure' => true]);
        }

        return $this->_redirect('checkout/cart', ['_secure' => true]);
    }

    public function saveCard($response)
    {

        // Save the card
        $success = $this->vaultHandler
            ->setCardToken($response->source['id'])
            ->setCustomerId()
            ->setCustomerEmail()
            ->setResponse($response)
            ->saveCard();

        // Prepare the response UI message
        if ($success) {
            $this->messageManager->addSuccessMessage(
                __('The payment card has been stored successfully.')
            );
        } else {
            $this->messageManager->addErrorMessage(
                __('The card could not be saved.')
            );
        }
    }
}
