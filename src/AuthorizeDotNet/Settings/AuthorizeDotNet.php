<?php

namespace Nails\Invoice\Driver\Payment\AuthorizeDotNet\Settings;

use Nails\Common\Helper\Form;
use Nails\Common\Interfaces;
use Nails\Common\Service\FormValidation;
use Nails\Components\Setting;
use Nails\Currency;
use Nails\Factory;

/**
 * Class AuthorizeDotNet
 *
 * @package Nails\Invoice\Driver\Payment\AuthorizeDotNet\Settings
 */
class AuthorizeDotNet implements Interfaces\Component\Settings
{
    const KEY_LABEL                      = 'sLabel';
    const KEY_STATEMENT_DESCRIPTOR       = 'sStatementDescriptor';
    const KEY_SUPPORTED_CURRENCY         = 'sSupportedCurrency';
    const KEY_LOGIN_ID_TEST              = 'sLoginIdTest';
    const KEY_PUBLIC_KEY_TEST            = 'sPublicKeyTest';
    const KEY_TRANSACTION_KEY_TEST       = 'sTransactionKeyTest';
    const KEY_SIGNATURE_KEY_TEST         = 'sSignatureKeyTest';
    const KEY_LOGIN_ID                   = 'sLoginId';
    const KEY_PUBLIC_KEY                 = 'sPublicKey';
    const KEY_TRANSACTION_KEY            = 'sTransactionKey';
    const KEY_SIGNATURE_KEY              = 'sSignatureKey';
    const KEY_PER_TRANSACTION_FEE        = 'iPerTransactionFee';
    const KEY_PER_TRANSACTION_PERCENTAGE = 'fPerTransactionPercentage';

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return 'Authorize.NET';
    }

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function get(): array
    {
        /** @var Currency\Service\Currency $oCurrency */
        $oCurrency = Factory::service('Currency', Currency\Constants::MODULE_SLUG);

        /** @var Setting $oLabel */
        $oLabel = Factory::factory('ComponentSetting');
        $oLabel
            ->setKey(static::KEY_LABEL)
            ->setLabel('Label')
            ->setInfo('The name of the provider, as seen by customers.')
            ->setDefault('Authorize.NET')
            ->setValidation([
                FormValidation::RULE_REQUIRED,
            ]);

        /** @var Setting $oStatementDescriptor */
        $oStatementDescriptor = Factory::factory('ComponentSetting');
        $oStatementDescriptor
            ->setKey(static::KEY_STATEMENT_DESCRIPTOR)
            ->setLabel('Statement Descriptor')
            ->setInfo('The text shown on the customer\'s statement. You can sub in <code>{{INVOICE_REF}}</code> for the invoice reference.')
            ->setDefault('INV #{{INVOICE_REF}}')
            ->setMaxLength(22)
            ->setValidation([
                FormValidation::RULE_REQUIRED,
                FormValidation::rule(FormValidation::RULE_MAX_LENGTH, 22),
            ]);

        /** @var Setting $oSupportedCurrencies */
        $oSupportedCurrencies = Factory::factory('ComponentSetting');
        $oSupportedCurrencies
            ->setKey(static::KEY_SUPPORTED_CURRENCY)
            ->setType(Form::FIELD_DROPDOWN)
            ->setLabel('Currency')
            ->setInfo('Authorize.net accounts only support a single currency')
            ->setClass('select2')
            ->setOptions(['' => 'Select currency...'] + $oCurrency->getAllFlat())
            ->setValidation([
                FormValidation::RULE_REQUIRED,
            ]);

        /** @var Setting $oLoginIdTest */
        $oLoginIdTest = Factory::factory('ComponentSetting');
        $oLoginIdTest
            ->setKey(static::KEY_LOGIN_ID_TEST)
            ->setType(Form::FIELD_PASSWORD)
            ->setLabel('Log-In ID')
            ->setEncrypted(true)
            ->setFieldset('API Keys - Test');

        /** @var Setting $oPublicKeyTest */
        $oPublicKeyTest = Factory::factory('ComponentSetting');
        $oPublicKeyTest
            ->setKey(static::KEY_PUBLIC_KEY_TEST)
            ->setType(Form::FIELD_PASSWORD)
            ->setLabel('Public Key')
            ->setEncrypted(true)
            ->setFieldset('API Keys - Test');

        /** @var Setting $oTransactionKeyTest */
        $oTransactionKeyTest = Factory::factory('ComponentSetting');
        $oTransactionKeyTest
            ->setKey(static::KEY_TRANSACTION_KEY_TEST)
            ->setType(Form::FIELD_PASSWORD)
            ->setLabel('Transaction Key')
            ->setEncrypted(true)
            ->setFieldset('API Keys - Test');

        /** @var Setting $oSignatureKeyTest */
        $oSignatureKeyTest = Factory::factory('ComponentSetting');
        $oSignatureKeyTest
            ->setKey(static::KEY_SIGNATURE_KEY_TEST)
            ->setType(Form::FIELD_PASSWORD)
            ->setLabel('Signature Key')
            ->setEncrypted(true)
            ->setFieldset('API Keys - Test');

        /** @var Setting $oLoginId */
        $oLoginId = Factory::factory('ComponentSetting');
        $oLoginId
            ->setKey(static::KEY_LOGIN_ID)
            ->setType(Form::FIELD_PASSWORD)
            ->setLabel('Log-In ID')
            ->setEncrypted(true)
            ->setFieldset('API Keys - Live');

        /** @var Setting $oPublicKey */
        $oPublicKey = Factory::factory('ComponentSetting');
        $oPublicKey
            ->setKey(static::KEY_PUBLIC_KEY)
            ->setType(Form::FIELD_PASSWORD)
            ->setLabel('Public Key')
            ->setEncrypted(true)
            ->setFieldset('API Keys - Live');

        /** @var Setting $oTransactionKey */
        $oTransactionKey = Factory::factory('ComponentSetting');
        $oTransactionKey
            ->setKey(static::KEY_TRANSACTION_KEY)
            ->setType(Form::FIELD_PASSWORD)
            ->setLabel('Transaction Key')
            ->setEncrypted(true)
            ->setFieldset('API Keys - Live');

        /** @var Setting $oSignatureKey */
        $oSignatureKey = Factory::factory('ComponentSetting');
        $oSignatureKey
            ->setKey(static::KEY_SIGNATURE_KEY)
            ->setType(Form::FIELD_PASSWORD)
            ->setLabel('Signature Key')
            ->setEncrypted(true)
            ->setFieldset('API Keys - Live');

        /** @var Setting $oPerTransactionFee */
        $oPerTransactionFee = Factory::factory('ComponentSetting');
        $oPerTransactionFee
            ->setKey(static::KEY_PER_TRANSACTION_FEE)
            ->setType(Form::FIELD_NUMBER)
            ->setLabel('Per Transaction Fee - Fixed')
            ->setInfo('The fixed component of the fee (in the smallest unit of the currency).')
            ->setDefault(0)
            ->setFieldset('Fees');

        /** @var Setting $oPerTransactionPercentage */
        $oPerTransactionPercentage = Factory::factory('ComponentSetting');
        $oPerTransactionPercentage
            ->setKey(static::KEY_PER_TRANSACTION_PERCENTAGE)
            ->setType(Form::FIELD_NUMBER)
            ->setLabel('Per Transaction Fee - Percentage')
            ->setInfo('The percentage component of the fee (0-100).')
            ->setDefault(0)
            ->setFieldset('Fees');

        return [
            $oLabel,
            $oStatementDescriptor,
            $oSupportedCurrencies,
            $oLoginIdTest,
            $oPublicKeyTest,
            $oTransactionKeyTest,
            $oSignatureKeyTest,
            $oLoginId,
            $oPublicKey,
            $oTransactionKey,
            $oSignatureKey,
            $oPerTransactionFee,
            $oPerTransactionPercentage,
        ];
    }
}
