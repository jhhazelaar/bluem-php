<?php

namespace Bluem\BluemPHP\Requests;

use Bluem\BluemPHP\Contexts\PaymentsContext;
use Bluem\BluemPHP\Helpers\BluemConfiguration;

use Carbon\Carbon;
use Exception;

class PaymentBluemRequest extends BluemRequest
{
    public $request_url_type = "pr";
    public $typeIdentifier = "createTransaction";
    public $transaction_code = "PTX";
    public string $dueDateTime;
    /**
     * @var array|string|string[]
     */
    public $debtorReturnURL;
    public string $paymentReference;
    private string $xmlInterfaceName = "EPaymentInterface";
    private string $description;
    /**
     * @var mixed|string
     */
    private $currency;
    private $debtorReference;
    private float $amount;

    /**
     * @throws Exception
     */
    public function __construct(
        BluemConfiguration $config,
        $description,
        $debtorReference,
        $amount,
        $dueDateTime = null,
        $currency = null,
        $transactionID = null,
        $entranceCode = "",
        string $expected_return = "none",
        $debtorReturnURL = ""
    ) {
        parent::__construct( $config, $entranceCode, $expected_return );

        if ( !empty( $config->paymentBrandID ) ) {
            $config->setBrandId( $config->paymentBrandID );
        } else {
            $config->setBrandId( $config->brandID );
        }

        $this->description = $this->_sanitizeDescription( $description );

        //  Default Currency EUR
        $this->currency = $this->validateCurrency( $currency );

        if ( is_null( $dueDateTime ) ) {
            $this->dueDateTime = Carbon::now()->addDay()->format( BLUEM_LOCAL_DATE_FORMAT ) . ".000Z";
        } else {
            $this->dueDateTime = Carbon::parse( $dueDateTime )->format( BLUEM_LOCAL_DATE_FORMAT ) . ".000Z";
        }

        //  @todo: validate DebtorReference : [0-9a-zA-Z]{1,35}
        $sanitizedDebtorReferenceParts = [];
        $sanitizedDebtorReferenceCount = preg_match_all(
            "/[\da-zA-Z]{1,35}/i",
            $debtorReference,
            $sanitizedDebtorReferenceParts
        );
        if ( $sanitizedDebtorReferenceCount !== false && $sanitizedDebtorReferenceCount > 0 ) {
            $debtorReference = implode(
                "",
                $sanitizedDebtorReferenceParts[0]
            );
        }
        $this->debtorReference = $debtorReference;


        $this->amount = (float)$amount;

        $this->transactionID = $transactionID;

        if ( isset( $debtorReturnURL ) && $debtorReturnURL !== "" ) {
            $this->debtorReturnURL = $debtorReturnURL;
        } else {
            $this->debtorReturnURL = $config->merchantReturnURLBase;
        }
        $this->debtorReturnURL .= "?entranceCode=$this->entranceCode&transactionID=$this->transactionID";

        $this->debtorReturnURL = str_replace( '&', '&amp;', $this->debtorReturnURL );

        // Note: different variable name in config
        // added entranceCode as well, useful. Defined in generic bluem request class.

        $this->paymentReference = "$this->debtorReference$this->transactionID";

        $this->context = new PaymentsContext();
    }

    /**
     * Validate based on a list of accepted currencies
     *
     * @param $currency
     *
     * @return string
     * @throws Exception
     */
    private function validateCurrency( $currency ): string {
        $availableCurrencies = [ "EUR" ]; // @todo: add list of currencies based on
        if ( !in_array($currency, $availableCurrencies, true)) {
            throw new Exception( "Currency not recognized,
                    should be one of the following available currencies: " .
                                 implode( ",", $availableCurrencies )
            );
        }

        return $currency;
    }

    public function TransactionType(): string {
        return $this->transaction_code;
    }

    public function XmlString(): string {
        $extraOptions = [
            'documentType' => "PayRequest",
            'sendOption'   => "none",
            'language'     => "nl",
        ];

        if (!empty($this->brandID))
        {
            $extraOptions['brandID'] = $this->brandID;
        }

        return $this->XmlRequestInterfaceWrap(
            $this->xmlInterfaceName,
            'TransactionRequest',
            $this->XmlRequestObjectWrap(
                'PaymentTransactionRequest',
                '<PaymentReference>' . $this->paymentReference . '</PaymentReference>
                <DebtorReference>' . $this->debtorReference . '</DebtorReference>
                <Description>' . $this->description . '</Description>
                <Currency>' . $this->currency . '</Currency>
                <Amount>' . number_format( $this->amount, 2, '.', '' ) . '</Amount>
                <DueDateTime>' . $this->dueDateTime . '</DueDateTime>
                <DebtorReturnURL automaticRedirect="1">' . $this->debtorReturnURL . '</DebtorReturnURL>' .
                $this->XmlWrapDebtorWalletForPaymentMethod() .
                $this->XmlWrapDebtorAdditionalData(),
                $extraOptions
            )
        );

        // @todo make documentType, sendOption and language a setting here?
    }

    private function setPaymentMethod(string $method): void {
        if(in_array($method, $this->context::PAYMENT_METHODS, true)) {
            $this->context->debtorWalletElementName = $method;
        }
    }

    public function setPaymentMethodToIDEAL($BIC = ""): self {
        $this->setPaymentMethod($this->context::PAYMENT_METHOD_IDEAL);

        /**
         * Add BIC to transaction if given
         */
        if (!empty($BIC))
        {
            $this->context->addPaymentMethodDetails([
                'BIC'=>$BIC
            ]);
        }

        return $this;
    }

    public function setPaymentMethodToPayPal($payPalAccount = ""): self {
        $this->setPaymentMethod($this->context::PAYMENT_METHOD_PAYPAL);

        /**
         * Prepared for future use.
         */
        if (!empty($payPalAccount))
        {
            $this->context->addPaymentMethodDetails([
                'PayPalAccount'=>$payPalAccount
            ]);
        }

        return $this;
    }

    public function setPaymentMethodToCreditCard(
        string $cardNumber = '', string $name = '', string $securityCode = '', string $expirationDateMonth = '', string $expirationDateYear = ''
    ): self {
        $this->setPaymentMethod($this->context::PAYMENT_METHOD_CREDITCARD);

        /**
         * Prepared for future use.
         */
        if (!empty($cardNumber) || !empty($name) || !empty($securityCode) || !empty($expirationDateMonth) || !empty($expirationDateYear))
        {
            $this->context->addPaymentMethodDetails([
                'CardNumber'=>$cardNumber,
                'Name'=>$name,
                'SecurityCode'=>$securityCode,
                'ExpirationDateMonth'=>$expirationDateMonth,
                'ExpirationDateYear'=>$expirationDateYear,
            ]);
        }

        return $this;
    }

    private function XmlWrapDebtorWalletForPaymentMethod(): string
    {
        $res = '';

        if ($this->context->isIDEAL()) {
            $bic = '';

            if (empty($this->context->getPaymentDetail('BIC'))) {
                if (!empty($this->debtorWallet)) {
                    $bic = $this->debtorWallet;
                }
            } else {
                $bic = $this->context->getPaymentDetail('BIC');
            }

            if (empty($bic)) {
                return '';
            }

            $res = PHP_EOL . "<DebtorWallet>" . PHP_EOL;
            $res .= "<{$this->context->debtorWalletElementName}>";
            $res .= "<BIC>" . $bic . "</BIC>";
            $res .= "</{$this->context->debtorWalletElementName}>" . PHP_EOL;
            $res .= "</DebtorWallet>" . PHP_EOL;

            return $res;
        }

        /**
         * For future use.
         *
         * $res = PHP_EOL . "<DebtorWallet>" . PHP_EOL;
         * $res .= "<{$this->context->debtorWalletElementName}>";

         * if($this->context->isPayPal()) {
         * $res .= "<PayPalAccount>" . $this->context->getPaymentDetail('PayPalAccount') . "</PayPalAccount>";
         * } elseif($this->context->isCreditCard()) {
         * $res .= "<CardNumber>" . $this->context->getPaymentDetail('CardNumber') . "</CardNumber>";
         * $res .= "<Name>" . $this->context->getPaymentDetail('Name') . "</Name>";
         * $res .= "<SecurityCode>" . $this->context->getPaymentDetail('SecurityCode') . "</SecurityCode>";
         * $res .= "<ExpirationDate>
         * <Month>" . $this->context->getPaymentDetail('ExpirationDateMonth') . "</Month>
         * <Year>" . $this->context->getPaymentDetail('ExpirationDateYear') . "</Year>
         * </ExpirationDate>";
         * }

         * $res .= "</{$this->context->debtorWalletElementName}>" . PHP_EOL;
         * $res .= "</DebtorWallet>" . PHP_EOL;
         *
         */

        return $res;
    }
}
