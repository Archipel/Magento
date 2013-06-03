<?php
/**
 * @covers Mollie_Mpm_IdlController
 */
class Mollie_Mpm_IdlControllerReportActionTest extends MagentoPlugin_TestCase
{
	/**
	 * @var Mollie_Mpm_IdlController|PHPUnit_Framework_MockObject_MockObject
	 */
	protected $controller;

	/**
	 * @var PHPUnit_Framework_MockObject_MockObject
	 */
	protected $request;

	/**
	 * @var PHPUnit_Framework_MockObject_MockObject
	 */
	protected $idealhelper;

	/**
	 * @var PHPUnit_Framework_MockObject_MockObject
	 */
	protected $datahelper;

	/**
	 * @var PHPUnit_Framework_MockObject_MockObject
	 */
	protected $order_model;

	/**
	 * @var PHPUnit_Framework_MockObject_MockObject
	 */
	protected $ideal_model;

	/**
	 * @var PHPUnit_Framework_MockObject_MockObject
	 */
	protected $payment_model;

	/**
	 * @var PHPUnit_Framework_MockObject_MockObject
	 */
	protected $transaction_model;

	/**
	 * @var PHPUnit_Framework_MockObject_MockObject
	 */
	protected $order;

	const TRANSACTION_ID = "1bba1d8fdbd8103b46151634bdbe0a60";

	const ORDER_ID = 1337;

	public function setUp()
	{
		parent::setUp();

		$this->controller = $this->getMock("Mollie_Mpm_IdlController", array("getRequest"), array());

		/**
		 * transaction_id is passed in from Mollie, must be checked in this code.
		 */
		$this->request = $this->getMock("stdClass", array("getParam"));
		$this->request->expects($this->atLeastOnce())
			->method("getParam")
			->with("transaction_id")
			->will($this->returnValue(self::TRANSACTION_ID));

		$this->controller->expects($this->any())
			->method("getRequest")
			->will($this->returnValue($this->request));

		$this->idealhelper = $this->getMock("Mollie_Mpm_Helper_Idl", array("getErrorMessage", "checkPayment", "getPaidStatus", "getAmount", "getBankStatus"), array(), "", FALSE);
		$this->datahelper  = $this->getMock("stdClass", array("getOrderIdByTransactionId"));

		/*
		 * Mage::Helper() method
		 */
		$this->mage->expects($this->any())
			->method("Helper")
			->will($this->returnValueMap(array(
			array("mpm/data", $this->datahelper),
			array("mpm/idl", $this->idealhelper),
		)));

		/*
		 * Models.
		 */
		$this->payment_model     = $this->getMock("Mage_Sales_Model_Order_Payment", array("setMethod", "setTransactionId", "setIsTransactionClosed", "addTransaction"));
		$this->ideal_model       = $this->getMock("Mollie_Mpm_Model_Idl", array("updatePayment"), array(), "", FALSE);
		$this->order_model       = $this->getMock("stdClass", array("load"));
		$this->transaction_model = $this->getMock("stdClass", array("addObject", "save"));
		$this->transaction_model->expects($this->any())
			->method($this->anything())
			->will($this->returnSelf());

		/*
		 * Mage::getModel() method
		 */
		$this->mage->expects($this->any())
			->method("getModel")
			->will($this->returnValueMap(array(
			array("mpm/idl", $this->ideal_model),
			array("sales/order", $this->order_model),
			array("sales/order_payment", $this->payment_model),
			array("core/resource_transaction", $this->transaction_model),
		)));

		$this->order = $this->getMock("Mage_Sales_Model_Order", array("canInvoice", "getData", "setPayment", "prepareInvoice", "getGrandTotal", "getAllItems", "setState", "sendNewOrderEmail", "setEmailSent", "cancel", "save"));
	}

	protected function expectsCheckPayment($returnValue)
	{
		/*
		 * Validate payment status with Mollie
		 */
		$this->idealhelper->expects($this->once())
			->method("checkPayment")
			->with(self::TRANSACTION_ID)
			->will($this->returnValue($returnValue));
	}

	protected function expectsPaidStatus($returnValue)
	{
		/*
		   * Payment status must be checked
		   */
		$this->idealhelper->expects($this->once())
			->method("getPaidStatus")
			->will($this->returnValue($returnValue));
	}

	protected function expectOrderState($expected_state)
	{
		/*
		 * Status must be checked with the order.
		 */
		$this->order->expects($this->once())
			->method("getData")
			->with("status")
			->will($this->returnValue($expected_state));
	}

	protected function expectsOrderModelCanBeloaded($success)
	{
		$this->datahelper->expects($this->once())
			->method("getOrderIdByTransactionId")
			->with(self::TRANSACTION_ID)
			->will($this->returnValue(self::ORDER_ID));

		$this->order_model->expects($this->once())
			->method("load")
			->with(self::ORDER_ID)
			->will($this->returnValue($success ? $this->order : NULL));
	}

	protected function expectBankStatus($bank_status)
	{
		$this->idealhelper->expects($this->atLeastOnce())
			->method("getBankStatus")
			->will($this->returnValue($bank_status));
	}

	protected function expectsPaymentSetupCorrectly()
	{
		/*
		 * Make sure Payment is stored correctly
		 */
		$this->payment_model->expects($this->once())->method("setMethod")->with("iDEAL")->will($this->returnValue($this->payment_model));
		$this->payment_model->expects($this->once())->method("setTransactionId")->with(self::TRANSACTION_ID)->will($this->returnValue($this->payment_model));
		$this->payment_model->expects($this->once())->method("setIsTransactionClosed")->with(TRUE)->will($this->returnValue($this->payment_model));

		/*
		 * Payment must be added to order
		 */
		$this->order->expects($this->once())
			->method("setPayment")
			->with($this->payment_model);
	}

	protected function expectOrderSaved()
	{
		$this->order->expects($this->once())
			->method("save");
	}

	protected function expectsOrderAmount($string_amount)
	{
		/*
		 * Put in the amounts
		 */
		$this->order->expects($this->atLeastOnce())
			->method("getGrandTotal")
			->will($this->returnValue($string_amount)); // Is a string, for realsies.
	}

	protected function expectsMollieAmount($amount_cents)
	{
		$this->idealhelper->expects($this->atLeastOnce())
			->method("getAmount")
			->will($this->returnValue($amount_cents));
	}

	public function testEverythingGoesGreat()
	{
		$this->expectsOrderModelCanBeloaded(TRUE);
		$this->expectOrderState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);

		$this->expectsCheckPayment(TRUE);
		$this->expectsPaidStatus(TRUE);

		$this->expectsPaymentSetupCorrectly();

		/*
		 * If successfull, add a capture transaction
		 */
		$this->order->expects($this->once())
			->method("canInvoice")
			->will($this->returnValue(TRUE));

		/** @var $mock_invoice Mage_Sales_Model_Order_Invoice|PHPUnit_Framework_MockObject_MockObject */
		$mock_invoice = $this->getMock("Mage_Sales_Model_Order_Invoice", array("register", "capture", "getOrder", "sendEmail"));

		$this->order->expects($this->once())
			->method("prepareInvoice")
			->will($this->returnValue($mock_invoice));

		$mock_invoice->expects($this->once())
			->method("capture")
			->will($this->returnSelf());

		$mock_invoice->expects($this->once())
			->method("register")
			->will($this->returnSelf());

		$mock_invoice->expects($this->any())
			->method("getOrder")
			->will($this->returnValue($this->order));

		$this->order->expects($this->once())
			->method("setState")
			->with(Mage_Sales_Model_Order::STATE_PROCESSING, Mage_Sales_Model_Order::STATE_PROCESSING, Mollie_Mpm_Model_Idl::PAYMENT_FLAG_PROCESSED, TRUE);

		$this->expectsMollieAmount(50015);
		$this->expectsOrderAmount("500.15");

		$this->expectOrderSaved();

		/*
		 * We must send an email is everything is successfull
		 */
		$this->order->expects($this->once())
			->method("sendNewOrderEmail")
			->will($this->returnValue($this->order));
		$this->order->expects($this->once())
			->method("setEmailSent")
			->with(TRUE);

		/*
		 * Skip items for now
		 */
		$this->order->expects($this->once())
			->method("getAllItems")
			->will($this->returnValue(array()));

		$this->ideal_model->expects($this->once())
			->method("updatePayment");

		$this->order->expects($this->never())
			->method("cancel");

		$this->controller->_construct();
		$this->controller->reportAction();
	}

	public function testNotPaid ()
	{
		$this->expectsOrderModelCanBeloaded(TRUE);
		$this->expectOrderState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);

		$this->expectsCheckPayment(TRUE);

		$this->expectsPaidStatus(FALSE);

		$this->expectsPaymentSetupCorrectly();

		$this->expectBankStatus("Cancelled");

		$this->ideal_model->expects($this->once())
			->method("updatePayment")
			->with(self::TRANSACTION_ID, "Cancelled");

		$this->order->expects($this->once())
			->method("cancel");

		$this->order->expects($this->once())
			->method("setState")
			->with(Mage_Sales_Model_Order::STATE_CANCELED, Mage_Sales_Model_Order::STATE_CANCELED, Mollie_Mpm_Model_Idl::PAYMENT_FLAG_CANCELD, FALSE);

		$this->expectOrderSaved();

		$this->controller->_construct();
		$this->controller->reportAction();
	}

	public function testAmountMisMatch()
	{
		$this->expectsOrderModelCanBeloaded(TRUE);
		$this->expectOrderState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);

		$this->expectsCheckPayment(TRUE);

		$this->expectsPaidStatus(TRUE);


		$this->expectsPaymentSetupCorrectly();

		$this->expectsMollieAmount(100);
		$this->expectsOrderAmount("200");

		$this->expectBankStatus(Mollie_Mpm_Model_Idl::IDL_SUCCESS);

		$this->ideal_model->expects($this->once())
			->method("updatePayment")
			->with(self::TRANSACTION_ID, Mollie_Mpm_Model_Idl::IDL_SUCCESS);

		$this->order->expects($this->never())
			->method("cancel");

		$this->order->expects($this->once())
			->method("setState")
			->with(Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW, Mage_Sales_Model_Order::STATUS_FRAUD, Mollie_Mpm_Model_Idl::PAYMENT_FLAG_FRAUD, FALSE);

		$this->expectOrderSaved();

		$this->controller->_construct();
		$this->controller->reportAction();
	}

	public function testExceptionThrownWhenCheckPaymentFails()
	{
		$this->expectsOrderModelCanBeloaded(TRUE);
		$this->expectOrderState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
		$this->expectsCheckPayment(FALSE);

		$this->idealhelper->expects($this->once())
			->method("getErrorMessage")
			->will($this->returnValue("The flux capacitors are over capacity"));

		$exception = new Test_Exception();

		$this->mage->expects($this->once())
			->method("throwException")
			->with("The flux capacitors are over capacity")
			->will($this->throwException($exception));

		$this->mage->expects($this->once())
			->method("log")
			->with($exception)
			->will($this->throwException($exception)); // Throw it again, we don't want to test _showException here.

		$this->setExpectedException("Test_Exception");

		$this->controller->_construct();
		$this->controller->reportAction();
	}
}

/**
 * @ignore
 */
class Test_Exception extends Exception {}