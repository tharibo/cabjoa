<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @category   Mage
 * @package    Mage_SalesRule
 * @copyright  Copyright (c) 2008 Irubin Consulting Inc. DBA Varien (http://www.varien.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */


/**
 * SalesRule Validator Model
 *
 * Allows dispatching before and after events for each controller action
 *
 * @category   Mage
 * @package    Mage_SalesRule
 * @author     Magento Core Team <core@magentocommerce.com>
 */
class Mage_SalesRule_Model_Validator extends Mage_Core_Model_Abstract
{
    /**
     * Rule source collection
     *
     * @var Mage_SalesRule_Model_Mysql4_Rule_Collection
     */
    protected $_rules;

    protected function _construct()
    {
        parent::_construct();
        $this->_init('salesrule/validator');
    }

    /**
     * Init validator
     *
     * @param int $websiteId
     * @param int $customerGroupId
     * @param string $couponCode
     * @return Mage_SalesRule_Model_Validator
     */
    public function init($websiteId, $customerGroupId, $couponCode)
    {
        $this->setWebsiteId($websiteId)
           ->setCustomerGroupId($customerGroupId)
           ->setCouponCode($couponCode);

        $this->_rules = Mage::getResourceModel('salesrule/rule_collection')
            ->setValidationFilter($websiteId, $customerGroupId, $couponCode)
            ->load();

        return $this;
    }

    public function process(Mage_Sales_Model_Quote_Item_Abstract $item)
    {
        $item->setFreeShipping(false);
        $item->setDiscountAmount(0);
        $item->setBaseDiscountAmount(0);
        $item->setDiscountPercent(0);

        $quote = $item->getQuote();
        if ($item instanceof Mage_Sales_Model_Quote_Address_Item) {
            $address = $item->getAddress();
        } elseif ($quote->isVirtual()) {
            $address = $quote->getBillingAddress();
        } else {
            $address = $quote->getShippingAddress();
        }

        $customerId = $quote->getCustomerId();
        $ruleCustomer = Mage::getModel('salesrule/rule_customer');
        $appliedRuleIds = array();

        foreach ($this->_rules as $rule) {
            /* @var $rule Mage_SalesRule_Model_Rule */
            /**
             * already tried to validate and failed
             */
            if ($rule->getIsValid() === false) {
                continue;
            }

            if ($rule->getIsValid() !== true) {
                /**
                 * too many times used in general
                 */
                if ($rule->getUsesPerCoupon() && ($rule->getTimesUsed() >= $rule->getUsesPerCoupon())) {
                    $rule->setIsValid(false);
                    continue;
                }
                /**
                 * too many times used for this customer
                 */
                $ruleId = $rule->getId();
                if ($ruleId && $rule->getUsesPerCustomer()) {
                    $ruleCustomer->loadByCustomerRule($customerId, $ruleId);
                    if ($ruleCustomer->getId()) {
                        if ($ruleCustomer->getTimesUsed() >= $rule->getUsesPerCustomer()) {
                            continue;
                        }
                    }
                }
                $rule->afterLoad();
                /**
                 * quote does not meet rule's conditions
                 */
                if (!$rule->validate($address)) {
                    $rule->setIsValid(false);
                    continue;
                }
                /**
                 * passed all validations, remember to be valid
                 */
                $rule->setIsValid(true);
            }

            /**
             * although the rule is valid, this item is not marked for action
             */
            if (!$rule->getActions()->validate($item)) {
                continue;
            }
            $qty = $item->getQty();
            if ($item->getParentItem()) {
                $qty*= $item->getParentItem()->getQty();
            }
            $qty = $rule->getDiscountQty() ? min($qty, $rule->getDiscountQty()) : $qty;
            $rulePercent = min(100, $rule->getDiscountAmount());
            $discountAmount = 0;
            $baseDiscountAmount = 0;
            switch ($rule->getSimpleAction()) {
                case 'to_percent':
                    $rulePercent = max(0, 100-$rule->getDiscountAmount());
                    //no break;

                case 'by_percent':
                    if ($step = $rule->getDiscountStep()) {
                        $qty = floor($qty/$step)*$step;
                    }
                    $discountAmount    = ($qty*$item->getCalculationPrice() - $item->getDiscountAmount()) * $rulePercent/100;
                    $baseDiscountAmount= ($qty*$item->getBaseCalculationPrice() - $item->getBaseDiscountAmount()) * $rulePercent/100;

                    if (!$rule->getDiscountQty() || $rule->getDiscountQty()>$qty) {
                        $discountPercent = min(100, $item->getDiscountPercent()+$rulePercent);
                        $item->setDiscountPercent($discountPercent);
                    }
                    break;

                case 'to_fixed':
                    $quoteAmount = $quote->getStore()->convertPrice($rule->getDiscountAmount());
                    $discountAmount    = $qty*($item->getCalculationPrice()-$quoteAmount);
                    $baseDiscountAmount= $qty*($item->getBaseCalculationPrice()-$rule->getDiscountAmount());
                    break;

                case 'by_fixed':
                    if ($step = $rule->getDiscountStep()) {
                        $qty = floor($qty/$step)*$step;
                    }
                    $quoteAmount = $quote->getStore()->convertPrice($rule->getDiscountAmount());
                    $discountAmount    = $qty*$quoteAmount;
                    $baseDiscountAmount= $qty*$rule->getDiscountAmount();
                    break;

                case 'cart_fixed':
                    $cartRules = $address->getCartFixedRules();
                    if (!isset($cartRules[$rule->getId()])) {
                        $cartRules[$rule->getId()] = $rule->getDiscountAmount();
                    }
                    if ($cartRules[$rule->getId()] > 0) {
                        $quoteAmount = $quote->getStore()->convertPrice($cartRules[$rule->getId()]);
                        $discountAmount = min($item->getRowTotal(), $quoteAmount);
                        $baseDiscountAmount = min($item->getBaseRowTotal(), $cartRules[$rule->getId()]);
                        $cartRules[$rule->getId()] -= $baseDiscountAmount;
                    }
                    $address->setCartFixedRules($cartRules);
                    break;

                case 'buy_x_get_y':
                    $x = $rule->getDiscountStep();
                    $y = $rule->getDiscountAmount();
                    if (!$x || $y>=$x) {
                        break;
                    }
                    $buy = 0; $free = 0;
                    while ($buy+$free<$qty) {
                        $buy += $x;
                        if ($buy+$free>=$qty) {
                            break;
                        }
                        $free += min($y, $qty-$buy-$free);
                        if ($buy+$free>=$qty) {
                            break;
                        }
                    }
                    $discountAmount    = $free*$item->getCalculationPrice();
                    $baseDiscountAmount= $free*$item->getBaseCalculationPrice();
                    break;

				case 'cheaper_items':
					Mage::log('VALIDATOR: CHEAPER ITEMS ON PROCESS');
					// First we construct a list of cheaper items. It's time 
					// consuming to recreate it for each item of the cart, but 
					// I can't find another way at this time.
					$items = $item->getQuote()->getAllVisibleItems();
					$itemsWithPrice = array();
					$validatedItemsWithQty = array();
					$validatedItemsQty = 0;
					foreach ($items as $currentQuoteItem) {
						// Argh, we have to recheck all items, for all items!
						if ($rule->getActions()->validate($currentQuoteItem)) {

							// Add validated item to the list
							$price = $currentQuoteItem->getProduct()->getPrice();
							$itemsWithPrice[$currentQuoteItem->getId()] = $price;

							// Compute quantity of validated items.
							$currentQuoteQty = $currentQuoteItem->getQty();
							if ($currentQuoteParent = $currentQuoteItem->getParentItem()) {
								$currentQuoteQty *= $currentQuoteParent->getQty();
							}
							$validatedItemsQty += $currentQuoteQty;
							$validatedItemsWithQty[$currentQuoteItem->getId()] = $currentQuoteQty;
						}
					}

					// Sort items by price
					asort( $itemsWithPrice );

					Mage::log('VALIDATOR: sorting done.');
					Mage::log('VALIDATOR: itemsWithPrice = ' . print_r($itemsWithPrice, TRUE));

					// Get only the cheaper items.
					// Reconstruct a list with n times the same item, given its quantity
					$nbCheapItems = intval($validatedItemsQty / 2);
					Mage::log('VALIDATOR: validatedItemsWithQty = ' . print_r($validatedItemsWithQty, TRUE));
					Mage::log('VALIDATOR: nbCheapItems = ' . $nbCheapItems);
					$nbProcessed = 0;
					$cheapItems = array();
					foreach ($itemsWithPrice as $validatedItem => $validatedPrice) {
						for ($i = 0; $i < $validatedItemsWithQty[$validatedItem] ; ++$i) {
							$cheapItems[] = $validatedItem;
							++$nbProcessed;
							if ($nbProcessed >= $nbCheapItems) {
								break;
							}
						}
						if ($nbProcessed >= $nbCheapItems) {
							break;
						}
					}
					
					Mage::log('VALIDATOR: construction of cheaper items ok.' . $nbProcessed);
					Mage::log('VALIDATOR: cheapItems' . print_r($cheapItems, TRUE));

					// Check that item tested against this rule is a cheap one.
					if (array_search( $item->getId(), $cheapItems ) !== FALSE) {
						// Apply discount
						Mage::log('VALIDATOR: applying discount');
						Mage::log('VALIDATOR: QTY = ' . $qty);
						if ($step = $rule->getDiscountStep()) {
							$qty = floor($qty/$step)*$step;
						}
						// The discount will be applied on this item the number 
						// of times it appears in $cheapItems
						$countValues = array_count_values( $cheapItems );
						$qty = min( $qty, $countValues[$item->getId()] );
						Mage::log('VALIDATOR: QTY = ' . $qty);

						$discountAmount    = ($qty*$item->getCalculationPrice() - $item->getDiscountAmount()) * $rulePercent/100;
						$baseDiscountAmount= ($qty*$item->getBaseCalculationPrice() - $item->getBaseDiscountAmount()) * $rulePercent/100;

						Mage::log('VALIDATOR: discountAmount: ' . $discountAmount);
						Mage::log('VALIDATOR: baseDiscountAmount: ' . $baseDiscountAmount);

						if (!$rule->getDiscountQty() || $rule->getDiscountQty()>$qty) {
							$discountPercent = min(100, $item->getDiscountPercent()+$rulePercent);
							$item->setDiscountPercent($discountPercent);
						}
					}

					break;
			}

            $result = new Varien_Object(array(
                'discount_amount'      => $discountAmount,
                'base_discount_amount' => $baseDiscountAmount,
            ));
            Mage::dispatchEvent('salesrule_validator_process', array(
                'rule'    => $rule,
                'item'    => $item,
                'address' => $address,
                'quote'   => $quote,
                'qty'     => $qty,
                'result'  => $result,
            ));

            $discountAmount = $result->getDiscountAmount();
            $baseDiscountAmount = $result->getBaseDiscountAmount();

            $discountAmount     = $quote->getStore()->roundPrice($discountAmount);
            $baseDiscountAmount = $quote->getStore()->roundPrice($baseDiscountAmount);
            $discountAmount     = min($item->getDiscountAmount()+$discountAmount, $item->getRowTotal());
            $baseDiscountAmount = min($item->getBaseDiscountAmount()+$baseDiscountAmount, $item->getBaseRowTotal());

            $item->setDiscountAmount($discountAmount);
            $item->setBaseDiscountAmount($baseDiscountAmount);

            switch ($rule->getSimpleFreeShipping()) {
                case Mage_SalesRule_Model_Rule::FREE_SHIPPING_ITEM:
                    $item->setFreeShipping($rule->getDiscountQty() ? $rule->getDiscountQty() : true);
                    break;

                case Mage_SalesRule_Model_Rule::FREE_SHIPPING_ADDRESS:
                    $address->setFreeShipping(true);
                    break;
            }

            $appliedRuleIds[$rule->getRuleId()] = $rule->getRuleId();

            if ($rule->getCouponCode() && ( strtolower($rule->getCouponCode()) == strtolower($this->getCouponCode()))) {
                $address->setCouponCode($this->getCouponCode());
            }

            if ($rule->getStopRulesProcessing()) {
                break;
            }
        }
        $item->setAppliedRuleIds(join(',',$appliedRuleIds));
        $address->setAppliedRuleIds($this->mergeIds($address->getAppliedRuleIds(), $appliedRuleIds));
        $quote->setAppliedRuleIds($this->mergeIds($quote->getAppliedRuleIds(), $appliedRuleIds));
        return $this;
    }

    public function mergeIds($a1, $a2, $asString=true)
    {
        if (!is_array($a1)) {
            $a1 = empty($a1) ? array() : explode(',', $a1);
        }
        if (!is_array($a2)) {
            $a2 = empty($a2) ? array() : explode(',', $a2);
        }
        $a = array_unique(array_merge($a1, $a2));
        if ($asString) {
           $a = implode(',', $a);
        }
        return $a;
    }
}
