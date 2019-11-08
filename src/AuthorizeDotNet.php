<?php

/**
 * Authorize.net payment Driver
 *
 * @package     Nails
 * @subpackage  driver-invoice-authorize-net
 * @category    Driver
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Invoice\Driver\Payment;

use Nails\Currency\Resource\Currency;
use Nails\Environment;
use Nails\Factory;
use Nails\Invoice;
use Nails\Invoice\Driver\Payment\AuthorizeDotNet\Constants;
use Nails\Invoice\Driver\PaymentBase;
use Nails\Invoice\Exception\ChargeRequestException;
use Nails\Invoice\Exception\DriverException;
use Nails\Invoice\Factory\ChargeRequest;
use Nails\Invoice\Factory\ChargeResponse;
use Nails\Invoice\Factory\CompleteResponse;
use Nails\Invoice\Factory\RefundResponse;
use Nails\Invoice\Factory\ScaResponse;
use Nails\Invoice\Resource;
use net\authorize\api\constants\ANetEnvironment as AuthNetConstants;
use net\authorize\api\contract\v1 as AuthNetAPI;
use net\authorize\api\controller as AuthNetController;
use stdClass;

/**
 * Class AuthorizeDotNet
 *
 * @package Nails\Invoice\Driver\Payment
 */
class AuthorizeDotNet extends PaymentBase
{
    /**
     * Returns whether the driver is available to be used against the selected invoice
     *
     * @param Resource\Invoice $oInvoice The invoice being charged
     *
     * @return bool
     */
    public function isAvailable(Resource\Invoice $oInvoice): bool
    {
        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the currencies which this driver supports, it will only be presented
     * when attempting to pay an invoice in a supported currency
     *
     * @return string[]|null
     */
    public function getSupportedCurrencies(): ?array
    {
        $sCode = appSetting('sSupportedCurrency', 'nails/driver-invoice-authorize-net');
        return $sCode ? [strtoupper($sCode)] : null;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns whether the driver uses a redirect payment flow or not.
     *
     * @return bool
     */
    public function isRedirect(): bool
    {
        return false;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the payment fields the driver requires, use static::PAYMENT_FIELDS_CARD for basic credit
     * card details.
     *
     * @return array|string
     */
    public function getPaymentFields()
    {
        return static::PAYMENT_FIELDS_CARD;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns any assets to load during checkout
     *
     * @return array
     */
    public function getCheckoutAssets(): array
    {
        return array_filter([
            Environment::is(Environment::ENV_PROD) ? [
                'https://js.authorize.net/v1/Accept.js',
                null,
                'JS',
            ] : null,
            Environment::not(Environment::ENV_PROD) ? [
                'https://jstest.authorize.net/v1/Accept.js',
                null,
                'JS',
            ] : null,
            [
                'checkout.min.js?' . implode('&', [
                    'hash=' . urlencode(md5($this->getSlug())) . '',
                    'key=' . urlencode($this->getEnvSetting('sPublicKey')) . '',
                    'loginId=' . urlencode($this->getEnvSetting('sLoginId')) . '',
                ]),
                $this->getSlug(),
                'JS',
            ],
        ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Prepares a ChargeRequest object
     *
     * @param ChargeRequest $oChargeRequest The ChargeRequest object to prepare
     * @param array         $aData          Any data which was requested by getPaymentFields()
     *
     * @throws ChargeRequestException
     */
    public function prepareChargeRequest(
        ChargeRequest $oChargeRequest,
        array $aData
    ): void {
        $this->setChargeRequestFields(
            $oChargeRequest,
            $aData,
            [['key' => 'token']]
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Initiate a payment
     *
     * @param int                           $iAmount      The payment amount
     * @param Currency                      $oCurrency    The payment currency
     * @param stdClass                      $oData        An array of driver data
     * @param Resource\Invoice\Data\Payment $oPaymentData The payment data object
     * @param string                        $sDescription The charge description
     * @param Resource\Payment              $oPayment     The payment object
     * @param Resource\Invoice              $oInvoice     The invoice object
     * @param string                        $sSuccessUrl  The URL to go to after successful payment
     * @param string                        $sErrorUrl    The URL to go to after failed payment
     * @param Resource\Source|null          $oSource      The saved payment source to use
     *
     * @return ChargeResponse
     */
    public function charge(
        int $iAmount,
        Currency $oCurrency,
        stdClass $oData,
        Resource\Invoice\Data\Payment $oPaymentData,
        string $sDescription,
        Resource\Payment $oPayment,
        Resource\Invoice $oInvoice,
        string $sSuccessUrl,
        string $sErrorUrl,
        bool $bCustomerPresent,
        Resource\Source $oSource = null
    ): ChargeResponse {

        /** @var ChargeResponse $oChargeResponse */
        $oChargeResponse = Factory::factory('ChargeResponse', Invoice\Constants::MODULE_SLUG);

        try {

            //  Begin creating the charge request
            $oCharge = new AuthNetAPI\TransactionRequestType();
            $oCharge->setTransactionType('authCaptureTransaction');

            // Create order information
            $oOrder = new AuthNetAPI\OrderType();
            $oOrder->setInvoiceNumber($oInvoice->ref);

            if (null !== $oSource) {

                /**
                 * The customer is checking out using a saved payment source
                 */
                $iPaymentProfileId  = getFromArray('payment_profile_id', (array) $oSource->data);
                $iCustomerProfileId = getFromArray('customer_profile_id', (array) $oSource->data);

                if (empty($iPaymentProfileId)) {
                    throw new DriverException('Could not ascertain the "source_id" from the Source object.');
                } elseif (empty($iCustomerProfileId)) {
                    throw new DriverException('Could not ascertain the "customer_id" from the Source object.');
                }

                $this->payUsingProfile(
                    $oCharge,
                    $iPaymentProfileId,
                    $iCustomerProfileId
                );

            } elseif (property_exists($oPaymentData, 'token')) {

                /**
                 * The customer is checking out using an Authorize.NET token
                 */
                $this->payUsingToken(
                    $oCharge,
                    $oPaymentData->token
                );

            } elseif (property_exists($oPaymentData, 'payment_profile_id') && property_exists($oPaymentData, 'customer_profile_id')) {

                /**
                 * Dev has passed explicit stripe source and customer IDs
                 */
                $this->payUsingProfile(
                    $oCharge,
                    $oPaymentData->payment_profile_id,
                    $oPaymentData->customer_profile_id
                );

            } else {
                throw new DriverException(
                    'Must provide a payment source, `token` or `payment_profile_id` and `customer_profile_id`.'
                );
            }

            $oCharge->setCurrencyCode($oCurrency->code);
            $oCharge->setAmount($iAmount / 100);
            $oCharge->setOrder($oOrder);

            $oApiRequest = new AuthNetAPI\CreateTransactionRequest();
            $oApiRequest->setMerchantAuthentication($this->getAuthentication());
            $oApiRequest->setRefId($oPayment->id);
            $oApiRequest->setTransactionRequest($oCharge);

            $oApiController = new AuthNetController\CreateTransactionController($oApiRequest);
            $oApiResponse   = $oApiController->executeWithApiResponse($this->getApiMode());

            if ($oApiResponse === null) {
                throw new DriverException(
                    'Received a null response from the payment gateway.'
                );
            }

            if ($oApiResponse->getMessages()->getResultCode() === Constants::API_RESPONSE_OK) {

                $oTransactionResponse = $oApiResponse->getTransactionResponse();
                $aErrors              = $oTransactionResponse->getErrors() ?? [];

                if ($oTransactionResponse === null) {
                    throw new DriverException(
                        'Received a null transaction response from the payment gateway.'
                    );
                } elseif (count($aErrors) > 0) {

                    $oError = reset($aErrors);

                    switch ((int) $oError->getErrorCode()) {
                        case Constants::TRANSACTION_CODE_DECLINED:
                            $sError     = 'The card was declined.';
                            $sUserError = $sError;
                            break;
                        case Constants::TRANSACTION_CODE_DECLINED_VOICE:
                            $sError     = 'The card was declined due to referral to voice authorisation centre.';
                            $sUserError = 'The card was declined.';
                            break;
                        case Constants::TRANSACTION_CODE_DECLINED_CARD_PICKUP:
                            $sError     = 'The card was declined due to card requiring pick up.';
                            $sUserError = 'The card was declined.';
                            break;
                        case Constants::TRANSACTION_CODE_DECLINED_INVALID_CARD_NUMBER:
                            $sError     = 'The card was declined due to an invalid card number.';
                            $sUserError = 'The card was declined.';
                            break;
                        case Constants::TRANSACTION_CODE_DECLINED_INVALID_EXPIRY:
                            $sError     = 'The card was declined due to an invalid expiry date.';
                            $sUserError = $sError;
                            break;
                        case Constants::TRANSACTION_CODE_DECLINED_INVALID_EXPIRY:
                            $sError     = 'The card is expired.';
                            $sUserError = $sError;
                            break;
                        default :
                            $sError     = 'The gateway rejected the request';
                            $sUserError = 'The gateway rejected the request, you may wish to try again.';
                            break;
                    }

                    $oChargeResponse->setStatusFailed(
                        $sError,
                        $oError->getErrorCode(),
                        $sUserError
                    );

                } else {
                    $oChargeResponse->setStatusComplete();
                    $oChargeResponse->setTransactionId($oTransactionResponse->getTransId());
                    $oChargeResponse->setFee($this->calculateFee($iAmount));
                }

            } else {

                $aGeneralErrors = $oApiResponse->getMessages()->getMessage();
                $oGeneralError  = reset($aGeneralErrors);
                $sSpecificError = '';

                if (is_callable([$oApiResponse->getTransactionResponse(), 'getErrors'])) {
                    $aErrors = $oApiResponse->getTransactionResponse()->getErrors();
                    $oError  = reset($aErrors);
                    if (!empty($oError)) {
                        $sSpecificError = ' ( ' . $oError->getErrorCode() . ': ' . $oError->getErrorText() . ')';
                    }
                }

                $oChargeResponse->setStatusFailed(
                    $oGeneralError->getText() . $sSpecificError,
                    $oGeneralError->getCode(),
                    'The gateway rejected the request, you may wish to try again.'
                );
            }

        } catch (DriverException $e) {
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'The gateway rejected the request, you may wish to try again.'
            );
        } catch (\Exception $e) {
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'An error occurred while executing the request.'
            );
        }

        return $oChargeResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Handles any SCA requests
     *
     * @param ScaResponse $oScaResponse The SCA Response object
     * @param array       $aData        Any saved SCA data
     * @param string      $sSuccessUrl  The URL to redirect to after authorisation
     *
     * @return ScaResponse
     */
    public function sca(ScaResponse $oScaResponse, array $aData, string $sSuccessUrl): ScaResponse
    {
        //  SCA is not supported... yet
    }

    // --------------------------------------------------------------------------

    /**
     * Complete the payment
     *
     * @param Resource\Payment $oPayment  The Payment object
     * @param Resource\Invoice $oInvoice  The Invoice object
     * @param array            $aGetVars  Any $_GET variables passed from the redirect flow
     * @param array            $aPostVars Any $_POST variables passed from the redirect flow
     *
     * @return CompleteResponse
     */
    public function complete(
        Resource\Payment $oPayment,
        Resource\Invoice $oInvoice,
        array $aGetVars,
        array $aPostVars
    ): CompleteResponse {
        /** @var CompleteResponse $oCompleteResponse */
        $oCompleteResponse = Factory::factory('CompleteResponse', Invoice\Constants::MODULE_SLUG);
        $oCompleteResponse->setStatusComplete();
        return $oCompleteResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Issue a refund for a payment
     *
     * @param string                        $sTransactionId The original transaction's ID
     * @param int                           $iAmount        The amount to refund
     * @param Currency                      $oCurrency      The currency in which to refund
     * @param Resource\Invoice\Data\Payment $oPaymentData   The payment data object
     * @param string                        $sReason        The refund's reason
     * @param Resource\Payment              $oPayment       The payment object
     * @param Resource\Invoice              $oInvoice       The invoice object
     *
     * @return RefundResponse
     */
    public function refund(
        string $sTransactionId,
        int $iAmount,
        Currency $oCurrency,
        Resource\Invoice\Data\Payment $oPaymentData,
        string $sReason,
        Resource\Payment $oPayment,
        Resource\Invoice $oInvoice
    ): RefundResponse {

        /** @var RefundResponse $oRefundResponse */
        $oRefundResponse = Factory::factory('RefundResponse', Invoice\Constants::MODULE_SLUG);

        try {

            //  Get the transaction details
            $oTransactionDetails = $this->getTransactionDetails($sTransactionId);

            //  Create order information
            $oOrder = new AuthNetAPI\OrderType();
            $oOrder->setInvoiceNumber($oInvoice->ref);

            //  Create the payment data for a credit card
            $oCard = new AuthNetAPI\CreditCardType();
            $oCard->setCardNumber($oTransactionDetails->card->last4);
            $oCard->setExpirationDate('XXXX'); //  This is deliberate

            $oPayment = new AuthNetAPI\PaymentType();
            $oPayment->setCreditCard($oCard);

            $oCharge = new AuthNetAPI\TransactionRequestType();
            $oCharge->setTransactionType('refundTransaction');
            $oCharge->setRefTransId($sTransactionId);
            $oCharge->setCurrencyCode($oCurrency->code);
            $oCharge->setAmount($iAmount / 100);
            $oCharge->setPayment($oPayment);
            $oCharge->setOrder($oOrder);

            $oApiRequest = new AuthNetAPI\CreateTransactionRequest();
            $oApiRequest->setMerchantAuthentication($this->getAuthentication());
            //  @todo (Pablo - 2018-01-31) - set this to the ID of the refund object
            //  $oApiRequest->setRefId(null);
            $oApiRequest->setTransactionRequest($oCharge);

            $oApiController = new AuthNetController\CreateTransactionController($oApiRequest);
            $oApiResponse   = $oApiController->executeWithApiResponse($this->getApiMode());

            if ($oApiResponse->getMessages()->getResultCode() === Constants::API_RESPONSE_OK) {

                $oRefundResponse->setStatusComplete();
                $oRefundResponse->setTransactionId($oApiResponse->getTransactionResponse()->getTransId());
                //  @todo (Pablo - 2018-01-31) - Calculate refunded fee
                //  $oRefundResponse->setFee($oStripeResponse->balance_transaction->fee * -1);

            } else {

                $aGeneralErrors = $oApiResponse->getMessages()->getMessage();
                $oGeneralError  = reset($aGeneralErrors);
                $sSpecificError = '';
                if (is_callable([$oApiResponse->getTransactionResponse(), 'getErrors'])) {
                    $aErrors = $oApiResponse->getTransactionResponse()->getErrors();
                    $oError  = reset($aErrors);
                    if (!empty($oError)) {
                        $sSpecificError = ' ( ' . $oError->getErrorCode() . ': ' . $oError->getErrorText() . ')';
                    }
                }

                $oRefundResponse->setStatusFailed(
                    $oGeneralError->getText() . $sSpecificError,
                    $oGeneralError->getCode(),
                    'The gateway rejected the request, you may wish to try again.'
                );
            }

        } catch (\Exception $e) {
            $oRefundResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'An error occurred while executing the request.'
            );
        }

        return $oRefundResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the mode to be used when running an Authorize.Net SDK controller.
     *
     * @return string
     */
    public function getApiMode(): string
    {
        return Environment::is(Environment::ENV_PROD) ? AuthNetConstants::PRODUCTION : AuthNetConstants::SANDBOX;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns an Authorize.Net authentication object
     *
     * @return AuthNetAPI\MerchantAuthenticationType
     */
    public function getAuthentication(): AuthNetAPI\MerchantAuthenticationType
    {
        $oAuthentication = new AuthNetAPI\MerchantAuthenticationType();
        $oAuthentication->setName($this->getEnvSetting('sLoginId'));
        $oAuthentication->setTransactionKey($this->getEnvSetting('sTransactionKey'));
        return $oAuthentication;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns an environment orientated setting
     *
     * @param string|null $sProperty the proeprty to fetch
     *
     * @return mixed
     */
    protected function getEnvSetting(string $sProperty = null)
    {
        if (Environment::is(Environment::ENV_PROD)) {
            return parent::getSetting($sProperty);
        } else {
            return parent::getSetting($sProperty . 'Test');
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Complete the charge using an existing payment profile.
     *
     * @param AuthNetAPI\TransactionRequestType $oCharge     The Charge object
     * @param int                               $iProfileId  The Payment profile ID
     * @param int                               $iCustomerId The customer profile ID
     *
     * @throws DriverException
     */
    protected function payUsingProfile(
        AuthNetAPI\TransactionRequestType $oCharge,
        int $iProfileId,
        int $iCustomerId
    ): void {

        if (empty($iProfileId)) {
            throw new DriverException('A Payment Profile ID must be supplied.');
        } elseif (empty($iCustomerId)) {
            throw new DriverException('A Customer Profile ID must be supplied.');
        }

        $oPaymentProfile = new AuthNetAPI\PaymentProfileType();
        $oPaymentProfile->setPaymentProfileId($iProfileId);

        $oCustomerProfile = new AuthNetAPI\CustomerProfilePaymentType();
        $oCustomerProfile->setCustomerProfileId($iCustomerId);
        $oCustomerProfile->setPaymentProfile($oPaymentProfile);

        $oCharge->setProfile($oCustomerProfile);
    }

    // --------------------------------------------------------------------------

    /**
     * Complete the charge using credit card details
     *
     * @param AuthNetAPI\TransactionRequestType $oCharge The Charge object
     * @param string                            $sToken  The payment token
     *
     * @throws DriverException
     */
    protected function payUsingToken(
        AuthNetAPI\TransactionRequestType $oCharge,
        string $sToken
    ): void {

        $oToken = json_decode($sToken);

        if (empty($oToken->dataDescriptor)) {
            throw new DriverException('Token must contain a dataDescriptor property.');
        } elseif (empty($oToken->dataValue)) {
            throw new DriverException('Token must contain a dataValue property.');
        }

        $oOpaqueData = new AuthNetAPI\OpaqueDataType();
        $oOpaqueData->setDataDescriptor($oToken->dataDescriptor);
        $oOpaqueData->setDataValue($oToken->dataValue);

        $oPayment = new AuthNetAPI\PaymentType();
        $oPayment->setOpaqueData($oOpaqueData);

        $oCharge->setPayment($oPayment);
    }

    // --------------------------------------------------------------------------

    /**
     * Calculates the transaction fee
     *
     * @param int $iAmount The value of the transaction
     *
     * @return int
     */
    protected function calculateFee($iAmount): int
    {
        $iFixedFee      = (int) $this->getSetting('iPerTransactionFee');
        $fPercentageFee = (float) $this->getSetting('iPerTransactionPercentage');
        return $iFixedFee + ($fPercentageFee / 100) * $iAmount;
    }

    // --------------------------------------------------------------------------

    /**
     * Queries Authorize.Net for details about a transaction
     *
     * @param string $sTransactionId The original transaction ID
     *
     * @return stdClass
     * @throws DriverException
     */
    protected function getTransactionDetails($sTransactionId): stdClass
    {
        $oApiRequest = new AuthNetAPI\GetTransactionDetailsRequest();
        $oApiRequest->setMerchantAuthentication($this->getAuthentication());
        $oApiRequest->setTransId($sTransactionId);

        $oApiController = new AuthNetController\GetTransactionDetailsController($oApiRequest);
        $oApiResponse   = $oApiController->executeWithApiResponse($this->getApiMode());

        if ($oApiResponse->getMessages()->getResultCode() === Constants::API_RESPONSE_OK) {

            $oTransaction = $oApiResponse->getTransaction();
            $oPayment     = $oTransaction->getPayment();
            $oCard        = $oPayment->getCreditCard();

            return (object) [
                'id'     => $oTransaction->getTransId(),
                'status' => $oTransaction->getTransactionStatus(),
                'card'   => (object) [
                    'last4' => substr($oCard->getCardNumber(), -4),
                ],
            ];

        } else {
            $aGeneralErrors = $oApiResponse->getMessages()->getMessage();
            $oGeneralError  = reset($aGeneralErrors);
            throw new DriverException($oGeneralError->getCode() . ': ' . $oGeneralError->getText());
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Creates a new payment source on the gateway, semi-populates the source resource with data
     *
     * @param Resource\Source $oResource The Resouce object to update
     * @param array           $aData     Data passed from the caller
     *
     * @throws DriverException
     */
    public function createSource(
        Resource\Source &$oResource,
        array $aData
    ): void {

        //  Required values
        $sCustomerProfileId = getFromArray('customer_profile_id', $aData);
        $sDataDescriptor    = getFromArray('data_descriptor', $aData);
        $sDataValue         = getFromArray('data_value', $aData);
        $sBrand             = trim(getFromArray('brand', $aData));
        $sLastFour          = getFromArray('last_four', $aData);
        $sExpiry            = getFromArray('expiry', $aData);
        $sName              = trim(getFromArray('name', $aData));

        $aErrors = [];
        if (empty($sCustomerProfileId)) {
            $aErrors[] = 'customer_profile_id';
        }
        if (empty($sDataDescriptor)) {
            $aErrors[] = 'data_descriptor';
        }
        if (empty($sDataValue)) {
            $aErrors[] = 'data_value';
        }
        if (empty($sBrand)) {
            $aErrors[] = 'brand';
        }
        if (empty($sLastFour)) {
            $aErrors[] = 'last_four';
        }
        if (empty($sExpiry)) {
            $aErrors[] = 'expiry';
        }
        if (empty($sName)) {
            $aErrors[] = 'name';
        }

        if (!empty($aErrors)) {
            $sError = '"' . implode('", "', $aErrors) . '""';
            $sError = replaceLastOccurrence(',', ' and', $sError);

            throw new DriverException(
                $sError . ' must be supplied when creating an Authorize.NET payment source.'
            );
        }

        list($sTitle, $sFirstName, $sLastName) = $this->getNameParts($sName);

        $oPaymentData = new AuthNetAPI\OpaqueDataType();
        $oPaymentData->setDataDescriptor($sDataDescriptor);
        $oPaymentData->setDataValue($sDataValue);

        $oPaymentSource = new AuthNetAPI\PaymentType();
        $oPaymentSource->setOpaqueData($oPaymentData);

        $oBillto = new AuthNetAPI\CustomerAddressType();
        $oBillto->setFirstName(trim($sTitle . ' ' . $sFirstName));
        $oBillto->setLastName($sLastName);

        $oPaymentProfile = new AuthNetAPI\CustomerPaymentProfileType();
        $oPaymentProfile->setCustomerType('individual');
        $oPaymentProfile->setPayment($oPaymentSource);
        $oPaymentProfile->setBillTo($oBillto);

        $oApiRequest = new AuthNetAPI\CreateCustomerPaymentProfileRequest();
        $oApiRequest->setMerchantAuthentication($this->getAuthentication());
        $oApiRequest->setCustomerProfileId($sCustomerProfileId);
        $oApiRequest->setPaymentProfile($oPaymentProfile);

        $oApiController = new AuthNetController\CreateCustomerPaymentProfileController($oApiRequest);
        $oApiResponse   = $oApiController->executeWithApiResponse($this->getApiMode());

        if ($oApiResponse->getMessages()->getResultCode() === Constants::API_RESPONSE_OK) {

            $oResource->name      = $sName;
            $oResource->brand     = $sBrand;
            $oResource->last_four = $sLastFour;
            $oResource->expiry    = $sExpiry;
            $oResource->data      = (object) [
                'payment_profile_id'  => $oApiResponse->getCustomerPaymentProfileId(),
                'customer_profile_id' => $sCustomerProfileId,
            ];

        } else {
            $aGeneralErrors = $oApiResponse->getMessages()->getMessage();
            $oGeneralError  = reset($aGeneralErrors);
            throw new DriverException($oGeneralError->getCode() . ': ' . $oGeneralError->getText());
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Updates a payment source on the gateway
     *
     * @param Resource\Source $oResource The Resource being updated
     *
     * @throws DriverException
     */
    public function updateSource(
        Resource\Source $oResource
    ): void {

        if (!property_exists($oResource->data, 'payment_profile_id')) {
            throw new DriverException(
                'Cannot delete remote source, object is malformed (missing "payment_profile_id")'
            );
        } elseif (!property_exists($oResource->data, 'customer_profile_id')) {
            throw new DriverException(
                'Cannot delete remote source, object is malformed (missing "customer_profile_id")'
            );
        }

        $oApiRequest = new AuthNetAPI\GetCustomerPaymentProfileRequest();
        $oApiRequest->setMerchantAuthentication($this->getAuthentication());
        $oApiRequest->setCustomerPaymentProfileId($oResource->data->payment_profile_id);
        $oApiRequest->setCustomerProfileId($oResource->data->customer_profile_id);

        $oApiController = new AuthNetController\GetCustomerPaymentProfileController($oApiRequest);
        /** @var AuthNetAPI\GetCustomerPaymentProfileResponse $oApiResponse */
        $oApiResponse = $oApiController->executeWithApiResponse($this->getApiMode());

        if ($oApiResponse->getMessages()->getResultCode() === Constants::API_RESPONSE_OK) {

            list($sTitle, $sFirstName, $sLastName) = $this->getNameParts($oResource->name);

            //  We have the existing card, submit an update request
            $oBillTo = $oApiResponse->getPaymentProfile()->getBillTo();
            $oBillTo->setFirstName(trim($sTitle . ' ' . $sFirstName));
            $oBillTo->setLastName($sLastName);

            $oCreditCard = new AuthNetAPI\CreditCardType();
            $oCreditCard->setCardNumber(
                $oApiResponse
                    ->getPaymentProfile()
                    ->getPayment()
                    ->getCreditCard()
                    ->getCardNumber()
            );
            if (is_string($oResource->expiry)) {
                $oExpiry = new \DateTime($oResource->expiry);
                $oCreditCard->setExpirationDate($oExpiry->format('Y-m'));
            } else {
                $oCreditCard->setExpirationDate($oResource->expiry->format('Y-m'));
            }

            $oPayment = new AuthNetAPI\PaymentType();
            $oPayment->setCreditCard($oCreditCard);

            $oPaymentprofile = new AuthNetAPI\CustomerPaymentProfileExType();
            $oPaymentprofile->setBillTo($oBillTo);
            $oPaymentprofile->setCustomerPaymentProfileId($oResource->data->payment_profile_id);
            $oPaymentprofile->setPayment($oPayment);

            // Submit a UpdatePaymentProfileRequest
            $oApiRequest = new AuthNetAPI\UpdateCustomerPaymentProfileRequest();
            $oApiRequest->setMerchantAuthentication($this->getAuthentication());
            $oApiRequest->setCustomerProfileId($oResource->data->customer_profile_id);
            $oApiRequest->setPaymentProfile($oPaymentprofile);

            $oApiController = new AuthNetController\UpdateCustomerPaymentProfileController($oApiRequest);
            /** @var AuthNetAPI\UpdateCustomerPaymentProfileResponse $oApiResponse */
            $oApiResponse = $oApiController->executeWithApiResponse($this->getApiMode());

            if ($oApiResponse->getMessages()->getResultCode() !== Constants::API_RESPONSE_OK) {
                $aGeneralErrors = $oApiResponse->getMessages()->getMessage();
                $oGeneralError  = reset($aGeneralErrors);
                throw new DriverException($oGeneralError->getCode() . ': ' . $oGeneralError->getText());
            }

        } else {
            $aGeneralErrors = $oApiResponse->getMessages()->getMessage();
            $oGeneralError  = reset($aGeneralErrors);
            throw new DriverException($oGeneralError->getCode() . ': ' . $oGeneralError->getText());
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Deletes a payment source from the gateway
     *
     * @param Resource\Source $oResource The Resource being deleted
     *
     * @throws DriverException
     */
    public function deleteSource(
        Resource\Source $oResource
    ): void {

        if (!property_exists($oResource->data, 'payment_profile_id')) {
            throw new DriverException(
                'Cannot delete remote source, object is malformed (missing "payment_profile_id")'
            );
        } elseif (!property_exists($oResource->data, 'customer_profile_id')) {
            throw new DriverException(
                'Cannot delete remote source, object is malformed (missing "customer_profile_id")'
            );
        }

        $oApiRequest = new AuthNetAPI\DeleteCustomerPaymentProfileRequest();
        $oApiRequest->setMerchantAuthentication($this->getAuthentication());
        $oApiRequest->setCustomerPaymentProfileId($oResource->data->payment_profile_id);
        $oApiRequest->setCustomerProfileId($oResource->data->customer_profile_id);

        $oApiController = new AuthNetController\DeleteCustomerPaymentProfileController($oApiRequest);
        /** @var AuthNetAPI\DeleteCustomerPaymentProfileResponse $oApiResponse */
        $oApiResponse = $oApiController->executeWithApiResponse($this->getApiMode());

        if ($oApiResponse->getMessages()->getResultCode() !== Constants::API_RESPONSE_OK) {
            $aGeneralErrors = $oApiResponse->getMessages()->getMessage();
            $oGeneralError  = reset($aGeneralErrors);
            throw new DriverException($oGeneralError->getCode() . ': ' . $oGeneralError->getText());
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Convinience method for creating a new customer on the gateway
     *
     * @param array $aData The driver specific customer data
     *
     * @return AuthNetAPI\CustomerProfileMaskedType
     * @throws DriverException
     */
    public function createCustomer(array $aData = []): AuthNetAPI\CustomerProfileMaskedType
    {
        if (empty($aData['merchant_customer_id']) && empty($aData['email']) && empty($aData['description'])) {
            throw new DriverException(
                'At least one must be supplied: "merchant_customer_id", "email", "description"'
            );
        }

        $oProfile = new AuthNetAPI\CustomerProfileType();
        if (array_key_exists('merchant_customer_id', $aData)) {
            $oProfile->setMerchantCustomerId($aData['merchant_customer_id']);
        }
        if (array_key_exists('email', $aData)) {
            $oProfile->setEmail($aData['email']);
        }
        if (array_key_exists('description', $aData)) {
            $oProfile->setDescription($aData['description']);
        }
        if (array_key_exists('payment_profiles', $aData)) {
            $oProfile->setPaymentProfiles($aData['payment_profiles']);
        }
        if (array_key_exists('shipping_profiles', $aData)) {
            $oProfile->setShipToList($aData['shipping_profiles']);
        }

        $oApiRequest = new AuthNetAPI\CreateCustomerProfileRequest();
        $oApiRequest->setMerchantAuthentication($this->getAuthentication());
        $oApiRequest->setProfile($oProfile);

        $oApiController = new AuthNetController\CreateCustomerProfileController($oApiRequest);
        $oApiResponse   = $oApiController->executeWithApiResponse($this->getApiMode());

        if ($oApiResponse->getMessages()->getResultCode() === Constants::API_RESPONSE_OK) {

            return $this->getCustomer($oApiResponse->getCustomerProfileId());

        } else {
            $aGeneralErrors = $oApiResponse->getMessages()->getMessage();
            $oGeneralError  = reset($aGeneralErrors);
            throw new DriverException($oGeneralError->getCode() . ': ' . $oGeneralError->getText());
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Convinience method for retrieving an existing customer from the gateway
     *
     * @param mixed $mCustomerId The gateway's customer ID
     * @param array $aData       Any driver specific data
     *
     * @return AuthNetAPI\CustomerProfileMaskedType
     * @throws DriverException
     */
    public function getCustomer($mCustomerId, array $aData = []): AuthNetAPI\CustomerProfileMaskedType
    {
        $oApiRequest = new AuthNetAPI\GetCustomerProfileRequest();
        $oApiRequest->setMerchantAuthentication($this->getAuthentication());
        $oApiRequest->setCustomerProfileId($mCustomerId);

        $oApiController = new AuthNetController\GetCustomerProfileController($oApiRequest);
        $oApiResponse   = $oApiController->executeWithApiResponse($this->getApiMode());

        if ($oApiResponse->getMessages()->getResultCode() === Constants::API_RESPONSE_OK) {

            return $oApiResponse->getProfile();

        } else {
            $aGeneralErrors = $oApiResponse->getMessages()->getMessage();
            $oGeneralError  = reset($aGeneralErrors);
            throw new DriverException($oGeneralError->getCode() . ': ' . $oGeneralError->getText());
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Convinience method for updating an existing customer on the gateway
     *
     * @param mixed $mCustomerId The gateway's customer ID
     * @param array $aData       The driver specific customer data
     *
     * @return AuthNetAPI\CustomerProfileMaskedType
     * @throws DriverException
     */
    public function updateCustomer($mCustomerId, array $aData = []): AuthNetAPI\CustomerProfileMaskedType
    {
        $oProfile = new AuthNetAPI\CustomerProfileExType();
        $oProfile->setCustomerProfileId($mCustomerId);

        if (array_key_exists('merchant_customer_id', $aData)) {
            $oProfile->setMerchantCustomerId($aData['merchant_customer_id']);
        }
        if (array_key_exists('email', $aData)) {
            $oProfile->setEmail($aData['email']);
        }
        if (array_key_exists('description', $aData)) {
            $oProfile->setEmail($aData['description']);
        }
        if (array_key_exists('payment_profiles', $aData)) {
            $oProfile->setPaymentProfiles($aData['payment_profiles']);
        }
        if (array_key_exists('shipping_profiles', $aData)) {
            $oProfile->setShipToList($aData['shipping_profiles']);
        }

        $oApiRequest = new AuthNetAPI\UpdateCustomerProfileRequest();
        $oApiRequest->setMerchantAuthentication($this->getAuthentication());
        $oApiRequest->setProfile($oProfile);

        $oApiController = new AuthNetController\UpdateCustomerProfileController($oApiRequest);
        $oApiResponse   = $oApiController->executeWithApiResponse($this->getApiMode());

        if ($oApiResponse->getMessages()->getResultCode() === Constants::API_RESPONSE_OK) {

            return $this->getCustomer($mCustomerId);

        } else {
            $aGeneralErrors = $oApiResponse->getMessages()->getMessage();
            $oGeneralError  = reset($aGeneralErrors);
            throw new DriverException($oGeneralError->getCode() . ': ' . $oGeneralError->getText());
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Convinience method for deleting an existing customer on the gateway
     *
     * @param mixed $mCustomerId The gateway's customer ID
     */
    public function deleteCustomer($mCustomerId): void
    {
        $oApiRequest = new AuthNetAPI\DeleteCustomerProfileRequest();
        $oApiRequest->setMerchantAuthentication($this->getAuthentication());
        $oApiRequest->setCustomerProfileId($mCustomerId);

        $oApiController = new AuthNetController\CreateCustomerProfileController($oApiRequest);
        $oApiResponse   = $oApiController->executeWithApiResponse($this->getApiMode());

        if ($oApiResponse->getMessages()->getResultCode() !== Constants::API_RESPONSE_OK) {
            $aGeneralErrors = $oApiResponse->getMessages()->getMessage();
            $oGeneralError  = reset($aGeneralErrors);
            throw new DriverException($oGeneralError->getCode() . ': ' . $oGeneralError->getText());
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Attempts to dissect a name into it's distinct parts
     *
     * @param $sName The name to dissect
     *
     * @return array
     */
    protected function getNameParts($sName)
    {
        if (preg_match('/^((m(r|s|rs|x|iss))|dr|master|sir|lady|madam|dame|lord|esq) /i', $sName)) {

            list($sTitle, $sFirstName, $sLastName) = array_pad(explode(' ', $sName, 3), 3, null);

            if (empty($sLastNfame)) {
                //  This supports "Mrs Test" type scenarios
                $sLastName  = $sFirstName;
                $sFirstName = $sTitle;
            } else {
                $sFirstName = trim($sTitle . ' ' . $sFirstName);
            }

        } else {
            $sTitle = null;
            list($sFirstName, $sLastName) = array_pad(explode(' ', $sName, 2), 2, null);
        }

        return [
            $sTitle,
            $sFirstName,
            $sLastName,
        ];
    }
}
