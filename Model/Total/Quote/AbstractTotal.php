<?php
/**
 * IDEALIAGroup srl
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to info@idealiagroup.com so we can send you a copy immediately.
 *
 * @category   MSP
 * @package    MSP_CashOnDelivery
 * @copyright  Copyright (c) 2016 IDEALIAGroup srl (http://www.idealiagroup.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace MSP\CashOnDelivery\Model\Total\Quote;

use Magento\Customer\Api\Data\AddressInterfaceFactory as CustomerAddressFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory as CustomerAddressRegionFactory;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Api\PaymentMethodManagementInterface;
//use Magento\Quote\Model\Quote\Address\Total\AbstractTotal as MageAbstractTotal;
use Magento\Tax\Model\Sales\Total\Quote\CommonTaxCollector;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Tax\Api\Data\TaxClassKeyInterface;
use MSP\CashOnDelivery\Api\CashondeliveryInterface;
use Magento\Tax\Helper\Data as TaxHelper;
use Magento\Framework\App\ObjectManager;


abstract class AbstractTotal extends CommonTaxCollector
{
    public const ITEM_TYPE_CASH_ON_DELIVERY = 'msp_cashondelivery';
    public const ITEM_CODE_CASH_ON_DELIVERY = 'msp_cashondelivery';

    /**
     * @var PaymentMethodManagementInterface
     */
    private $paymentMethodManagement;

    protected $cashOnDeliveryInterface;
    protected $priceCurrencyInterface;

    /**
     * @var TaxHelper
     */
    private $taxHelper;

    /**
     * Class constructor
     *
     * @param \Magento\Tax\Model\Config $taxConfig
     * @param \Magento\Tax\Api\TaxCalculationInterface $taxCalculationService
     * @param \Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory $quoteDetailsDataObjectFactory
     * @param \Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory $quoteDetailsItemDataObjectFactory
     * @param \Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory $taxClassKeyDataObjectFactory
     * @param CustomerAddressFactory $customerAddressFactory
     * @param CustomerAddressRegionFactory $customerAddressRegionFactory
     * @param PaymentMethodManagementInterface $paymentMethodManagement
     * @param PriceCurrencyInterface $priceCurrencyInterface
     * @param CashondeliveryInterface $cashOnDeliveryInterface
     * @param TaxHelper $taxHelper
     */
    public function __construct(
        \Magento\Tax\Model\Config $taxConfig,
        \Magento\Tax\Api\TaxCalculationInterface $taxCalculationService,
        \Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory $quoteDetailsDataObjectFactory,
        \Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory $quoteDetailsItemDataObjectFactory,
        \Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory $taxClassKeyDataObjectFactory,
        CustomerAddressFactory $customerAddressFactory,
        CustomerAddressRegionFactory $customerAddressRegionFactory,
        PaymentMethodManagementInterface $paymentMethodManagement,
        PriceCurrencyInterface $priceCurrencyInterface,
        CashondeliveryInterface $cashOnDeliveryInterface,
        TaxHelper $taxHelper = null
    ) {
        $this->setCode('msp_cashondelivery');
        parent::__construct(            
            $taxConfig,
            $taxCalculationService,
            $quoteDetailsDataObjectFactory,
            $quoteDetailsItemDataObjectFactory,
            $taxClassKeyDataObjectFactory,
            $customerAddressFactory,
            $customerAddressRegionFactory
        );
        $this->paymentMethodManagement = $paymentMethodManagement;
        $this->cashOnDeliveryInterface = $cashOnDeliveryInterface;
        $this->priceCurrencyInterface = $priceCurrencyInterface;
        $this->taxHelper = $taxHelper ?: ObjectManager::getInstance()->get(TaxHelper::class);
    }

    /**
     * Return true if can apply totals
     * @param Quote $quote
     * @return bool
     */
    protected function _canApplyTotal(Quote $quote)
    {
        // FIX bug issue #29
        if (!$quote->getId()) {
            return false;
        }
        $paymentMethodsList = $this->paymentMethodManagement->getList($quote->getId());
        if ((count($paymentMethodsList) == 1) && (current($paymentMethodsList)->getCode() == 'msp_cashondelivery')) {
            return true;
        }

        return ($quote->getPayment()->getMethod() == 'msp_cashondelivery');
    }


    /**
     * Get cash on delivery data object.
     *
     * @param ShippingAssignmentInterface $shippingAssignment
     * @param QuoteAddress\Total $total
     * @param bool $useBaseCurrency
     * @return QuoteDetailsItemInterface
     */
    public function getCashOnDeliveryDataObject(
        ShippingAssignmentInterface $shippingAssignment,
        QuoteAddress\Total $total,
        $useBaseCurrency
    ) {
        $store = $shippingAssignment->getShipping()->getAddress()->getQuote()->getStore();
        if ($total->getMspCodTaxCalculationAmount() === null) {
            //Save the original cash on delivery amount because cash on delivery amount will be overridden
            //with cash on delivery amount excluding tax
            $total->setMspCodTaxCalculationAmount($total->getMspCodAmount());
            $total->setBaseMspCodTaxCalculationAmount($total->getBaseMspCodAmount());
        }
        if ($total->getMspCodTaxCalculationAmount() !== null) {
            /** @var QuoteDetailsItemInterface $itemDataObject */
            $itemDataObject = $this->quoteDetailsItemDataObjectFactory->create()
                ->setType(self::ITEM_TYPE_CASH_ON_DELIVERY)
                ->setCode(self::ITEM_CODE_CASH_ON_DELIVERY)
                ->setQuantity(1);
            if ($useBaseCurrency) {
                $itemDataObject->setUnitPrice($total->getBaseMspCodTaxCalculationAmount());
            } else {
                $itemDataObject->setUnitPrice($total->getMspCodTaxCalculationAmount());
            }
            $itemDataObject->setTaxClassKey(
                $this->taxClassKeyDataObjectFactory->create()
                    ->setType(TaxClassKeyInterface::TYPE_ID)
                    ->setValue($this->_config->getShippingTaxClass($store))
            );
            $itemDataObject->setIsTaxIncluded(
                $this->taxHelper->shippingPriceIncludesTax()
            );
            return $itemDataObject;
        }

        return null;
    }

    /**
     * Update tax related fields for shipping
     *
     * @param ShippingAssignmentInterface $shippingAssignment
     * @param QuoteAddress\Total $total
     * @param TaxDetailsItemInterface $mspCodTaxDetails
     * @param TaxDetailsItemInterface $baseMspCodTaxDetails
     * @return $this
     */
    protected function processMspCodTaxInfo(
        ShippingAssignmentInterface $shippingAssignment,
        QuoteAddress\Total $total,
        $mspCodTaxDetails,
        $baseMspCodTaxDetails
    ) {
        $total->setTotalAmount('msp_cashondelivery', $mspCodTaxDetails->getRowTotal());
        $total->setBaseTotalAmount('msp_cashondelivery', $baseMspCodTaxDetails->getRowTotal());

        $total->setMspCodInclTax($mspCodTaxDetails->getRowTotalInclTax());
        $total->setBaseMspCodInclTax($baseMspCodTaxDetails->getRowTotalInclTax());
        $total->setMspCodTaxAmount($mspCodTaxDetails->getRowTax());
        $total->setBaseMspCodTaxAmount($baseMspCodTaxDetails->getRowTax());

        //Add the cash on delivery tax to total tax amount
        $total->addTotalAmount('tax', $mspCodTaxDetails->getRowTax());
        $total->addBaseTotalAmount('tax', $baseMspCodTaxDetails->getRowTax());

        return $this;
    }


}
