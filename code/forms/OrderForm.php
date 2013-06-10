<?php



/**
 * @Description: form to submit order.
 * @see CheckoutPage
 *
 * @authors: Nicolaas [at] Sunny Side Up .co.nz
 * @package: ecommerce
 * @sub-package: forms
 * @inspiration: Silverstripe Ltd, Jeremy
 **/

class OrderForm extends Form {

	/**
	 *
	 * @param Controller $controller
	 * @param String
	 */
	function __construct(Controller $controller, $name) {

		//requirements
		Requirements::javascript('ecommerce/javascript/EcomOrderForm.js');

		//set basics
		$order = ShoppingCart::current_order();
		$order->calculateOrderAttributes($force = false);
		$requiredFields = array();


		//  ________________  3) Payment fields - BOTTOM FIELDS



		$bottomFields = new CompositeField();

		$bottomFields->setID('BottomOrder');
		if($order->Total() > 0) {
			$bottomFields->push(new HeaderField("PaymentHeader", _t("OrderForm.PAYMENT", "Payment"), 3));
			$paymentFields = EcommercePayment::combined_form_fields($order->getTotalAsMoney()->NiceDefaultFormat(false));
			foreach($paymentFields as $paymentField) {
				$bottomFields->push($paymentField);
			}
			if($paymentRequiredFields = EcommercePayment::combined_form_requirements()) {
				$requiredFields = array_merge($requiredFields, $paymentRequiredFields);
			}
		}
		else {
			$bottomFields->push(new HiddenField("PaymentMethod", "", ""));
		}


		//  ________________  4) FINAL FIELDS


		$finalFields = new CompositeField();
		$finalFields->setID('FinalFields');
		$finalFields->push(new HeaderField('CompleteOrder', _t('OrderForm.COMPLETEORDER','Complete Order'), 3));
		// If a terms and conditions page exists, we need to create a field to confirm the user has read it
		if($termsAndConditionsPage = CheckoutPage::find_terms_and_conditions_page()) {
			$checkoutPage = CheckoutPage::get()->First();
			if($checkoutPage && $checkoutPage->TermsAndConditionsMessage) {
				$alreadyTicked = false;
				$requiredFields[] = 'ReadTermsAndConditions';
			}
			else {
				$alreadyTicked = true;
			}
			$finalFields->push(new CheckboxField('ReadTermsAndConditions', _t('OrderForm.AGREEWITHTERMS1','I have read and agree with the ').' <a href="'.$termsAndConditionsPage->Link().'">'.Convert::raw2xml($termsAndConditionsPage->Title).'</a>'._t('OrderForm.AGREEWITHTERMS2','.'), $alreadyTicked));
		}
		$textAreaField = new TextareaField('CustomerOrderNote', _t('OrderForm.CUSTOMERNOTE','Note / Question'));
		$finalFields->push($textAreaField);


		//  ________________  5) Put all the fields in one FieldList


		$fields = new FieldList($bottomFields, $finalFields);



		//  ________________  6) Actions and required fields creation + Final Form construction


		$actions = new FieldList(new FormAction('processOrder', _t('OrderForm.PROCESSORDER','Place order and make payment')));
		$validator = new OrderForm_Validator($requiredFields);
		//we stick with standard validation here, because of the complexity and
		//hard-coded payment validation that is required
		parent::__construct($controller, $name, $fields, $actions, $validator);
		$this->setAttribute("autocomplete", "off");
		//extensions need to be set after __construct
		if($this->extend('updateFields', $fields) !== null) {$this->setFields($fields);}
		if($this->extend('updateActions', $actions) !== null) {$this->setActions($actions);}
		if($this->extend('updateValidator', $validator) !== null) {$this->setValidator($validator);}

		//  ________________  7)  Load saved data

		if($order) {
			$this->loadDataFrom($order);
		}

		//allow updating via decoration
		$this->extend('updateOrderForm',$this);

	}

	/**
	 * Process final confirmation and payment
	 *
	 * {@link Payment} instance is created, linked to the order,
	 * and payment is processed {@link EcommercePayment::processPayment()}
	 *
	 * @param array $data Form request data submitted from OrderForm
	 * @param Form $form Form object for this action
	 * @param HTTPRequest $request Request object for this action
	 */
	function processOrder(Array $data, Form $form, SS_HTTPRequest $request) {
		$this->saveDataToSession($data); //save for later if necessary
		$order = ShoppingCart::current_order();
		//check for cart items
		if(!$order) {
			$form->sessionMessage(_t('OrderForm.ORDERNOTFOUND','Your order could not be found.'), 'bad');
			$this->controller->redirectBack();
			return false;
		}
		if($order && $order->TotalItems($recalculate = true) < 1) {
			// WE DO NOT NEED THE THING BELOW BECAUSE IT IS ALREADY IN THE TEMPLATE AND IT CAN LEAD TO SHOWING ORDER WITH ITEMS AND MESSAGE
			$form->sessionMessage(_t('Order.NOITEMSINCART','Please add some items to your cart.'), 'bad');
			$this->controller->redirectBack();
			return false;
		}

		if($this->extend("OrderFormBeforeFinalCalculation", $data, $form, $request)) {
			$form->sessionMessage(_t('Order.ERRORWITHFORM','There was an error with your order, please review and submit again.'), 'bad');
			$this->controller->redirectBack();
			return false;
		}

		//RUN UPDATES TO CHECK NOTHING HAS CHANGED
		$oldTotal = $order->Total();
		//if the extend line below does not return null then we know there
		// is an error in the form (e.g. Payment Option not entered)
		$order->calculateOrderAttributes($force = true);
		$newTotal = $order->Total();
		if(floatval($newTotal) != floatval($oldTotal)) {
			$form->sessionMessage(_t('OrderForm.PRICEUPDATED','The order price has been updated, please review the order and submit again.'), 'warning');
			$this->controller->redirectBack();
			return false;
		}

		//saving into order
		$form->saveInto($order);
		//saving into member, in case we add additional fields for the member
		//e.g. newslettersignup
		if($member = Member::currentUser()) {
			$form->saveInto($member);
		}
		$order->write();

		//----------------- CLEAR OLD DATA ------------------------------
		$this->clearSessionData(); //clears the stored session form data that might have been needed if validation failed
		//----------------- PAYMENT ------------------------------

		//-------------- NOW SUBMIT -------------
		$this->extend("OrderFormBeforeSubmit", $order);
		// this should be done before paying, as only submitted orders can be paid!
		ShoppingCart::singleton()->submit();
		$this->extend("OrderFormAfterSubmit", $order);

		//-------------- ACTION PAYMENT -------------
		$payment = EcommercePayment::process_payment_form_and_return_next_step($order, $form, $data);

		//-------------- DO WE HAVE ANY PROGRESS NOW -------------
		$order->tryToFinaliseOrder();
		//any changes to the order at this point can be taken care by ordsteps.

		//------------- WHAT DO WE DO NEXT? -----------------
		if($payment) {
			//redirection is taken care of by EcommercePayment
			return $payment;
		}
		else {
			//there is an error with payment
			if(!Controller::curr()->redirectedTo()) {
				$this->controller->redirect($order->Link());
			}
			return false;
		}
		//------------------------------
	}

	/**
	 * saves the form into session
	 * @param Array $data - data from form.
	 */
	function saveDataToSession(Array $data){
		Session::set("FormInfo.{$this->FormName()}.data", $data);
	}

	/**
	 * loads the form data from the session
	 * @return Array
	 */
	function loadDataFromSession(){
		if($data = Session::get("FormInfo.{$this->FormName()}.data")){
			$this->loadDataFrom($data);
		}
	}


	/**
	 * clear the form data (after the form has been submitted and processed)
	 */
	function clearSessionData(){
		$this->clearMessage();
		Session::set("FormInfo.{$this->FormName()}.data", null);
	}


	/**
	 * returns the instance of EcommerceDBConfig
	 *
	 * @return EcommerceDBConfig
	 **/
	protected function EcomConfig(){
		return EcommerceDBConfig::current_ecommerce_db_config();
	}

}


/**
 * @Description: checks the data for the OrderForm, before submission.
 *
 *
 * @authors: Nicolaas [at] Sunny Side Up .co.nz
 * @package: ecommerce
 * @sub-package: forms
 * @inspiration: Silverstripe Ltd, Jeremy
 **/

class OrderForm_Validator extends RequiredFields{

	/**
	 * Ensures member unique id stays unique and other basic stuff...
	 * @param array $data = Form Data
	 * @return Boolean
	 */
	function php($data){
		$valid = parent::php($data);
		$checkoutPage = CheckoutPage::get()->First();
		if($checkoutPage && $checkoutPage->TermsAndConditionsMessage) {
			if(isset($data["ReadTermsAndConditions"])) {
				if(!$data["ReadTermsAndConditions"]) {
					$this->validationError(
						"ReadTermsAndConditions",
						$checkoutPage->TermsAndConditionsMessage,
						"required"
					);
					$valid = false;
				}
			}
		}
		$order = ShoppingCart::current_order();
		if(!$order) {
			$this->validationError(
				"Order",
				_t("OrderForm.ORDERNOTFOUND", "There was an error in processing your order, please try again or contact the administrator."),
				"required"
			);
			$valid = false;
		}
		$billingAddress = BillingAddress::get()->byID($order->BillingAddressID);
		if(!$billingAddress) {
			$this->validationError(
				"BillingAddress",
				_t("OrderForm.MUSTHAVEBILLINGADDRESS", "All orders must have a billing address, please go back and add your details."),
				"required"
			);
			$valid = false;
		}
		return $valid;
	}


}


/**
 * @Description: allows customer to make additional payments for their order
 *
 * @authors: Nicolaas [at] Sunny Side Up .co.nz
 * @package: ecommerce
 * @sub-package: forms
 * @inspiration: Silverstripe Ltd, Jeremy
 **/

class OrderForm_Payment extends Form {

	/**
	 * @param Controller $controller
	 * @param String $name
	 * @param Order $order
	 * @param String
	 */
	function __construct(Controller $controller, $name, Order $order, $returnToLink = '') {
		$requiredFields = null;
		$fields = new FieldList(
			new HiddenField('OrderID', '', $order->ID)
		);
		if($returnToLink) {
			$fields->push(new HiddenField("returntolink", "", convert::raw2att($returnToLink)));
		}

		$bottomFields = new CompositeField();
		$bottomFields->setID('BottomOrder');
		if($order->Total() > 0) {
			$paymentFields = EcommercePayment::combined_form_fields($order->getTotalAsMoney()->NiceDefaultFormat(false));
			foreach($paymentFields as $paymentField) {
				$bottomFields->push($paymentField);
			}
			if($paymentRequiredFields = EcommercePayment::combined_form_requirements()) {
				$requiredFields = array_merge($requiredFields, $paymentRequiredFields);
			}
		}
		else {
			$bottomFields->push(new HiddenField("PaymentMethod", "", ""));
		}
		$fields->push($bottomFields);

		$actions = new FieldList(
			new FormAction('dopayment', _t('OrderForm.PAYORDER','Pay balance'))
		);
		$form = parent::__construct($controller, $name, $fields, $actions, $requiredFields);
		if($this->extend('updateFields', $fields) !== null) {$this->setFields($fields);}
		if($this->extend('updateActions', $actions) !== null) {$this->setActions($actions);}
		if($this->extend('updateValidator', $validator) !== null) {$this->setValidator($validator);}
		$this->setFormAction($controller->Link($name));
		$this->extend('updateOrderFormPayment', $this);
	}

	function dopayment($data, $form) {
		$SQLData = Convert::raw2sql($data);
		if(isset($SQLData['OrderID'])) {
			if($orderID = intval($SQLData['OrderID'])) {
				$order = Order::get_by_id_if_can_view($orderID);
				if($order && $order->canPay()) {
					return EcommercePayment::process_payment_form_and_return_next_step($order, $form, $data);
				}
			}
		}
		$form->sessionMessage(_t('OrderForm.COULDNOTPROCESSPAYMENT','Sorry, we could not process your payment.'),'bad');
		$this->controller->redirectBack();
		return false;
	}

}



/**
 * @Description: allows customer to cancel their order.
 *
 * @authors: Nicolaas [at] Sunny Side Up .co.nz
 * @package: ecommerce
 * @sub-package: forms
 * @inspiration: Silverstripe Ltd, Jeremy
 **/

class OrderForm_Cancel extends Form {

	function __construct(Controller $controller, $name, Order $order) {
		$fields = new FieldList(
			array(
				new HeaderField('CancelOrderHeading', _t("OrderForm.CANCELORDER", "Changed your mind?"), 3),
				new TextField('CancellationReason', _t("OrderForm.CANCELLATIONREASON", "Reason for cancellation")),
				new HiddenField('OrderID', '', $order->ID)
			)
		);
		$actions = new FieldList(
			new FormAction('docancel', _t('OrderForm.CANCELORDER','Cancel this order'))
		);
		$requiredFields = array();
		parent::__construct($controller, $name, $fields, $actions);
		if($this->extend('updateFields', $fields) !== null) {$this->setFields($fields);}
		if($this->extend('updateActions', $actions) !== null) {$this->setActions($actions);}
		if($this->extend('updateValidator', $requiredFields) !== null) {$this->setValidator($requiredFields);}
	}

	/**
	 * Form action handler for OrderForm_Cancel.
	 *
	 * Take the order that this was to be change on,
	 * and set the status that was requested from
	 * the form request data.
	 *
	 * @param array $data The form request data submitted
	 * @param Form $form The {@link Form} this was submitted on
	 */
	function docancel(Array $data, Form $form, SS_HTTPRequest $request) {
		$SQLData = Convert::raw2sql($data);
		$member = Member::currentUser();
		if($member) {
			if(isset($SQLData['OrderID'])){
				$order = Order::get()->byID(intval($SQLData['OrderID']));
				if($order) {
					if($order->canCancel()) {
						$reason = "";
						if(isset($SQLData["CancellationReason"])) {
							$reason = $SQLData["CancellationReason"];
						}
						$order->Cancel($member, $reason);
						return $this->controller->redirectBack();
					}
					$order->Cancel($member, $reason);
					$this->controller->redirectBack();
					return false;
				}
			}
		}
		$form->sessionMessage(
			_t(
				'OrderForm.COULDNOTCANCELORDER',
				'Sorry, order could not be cancelled.'
			),
			'bad'
		);
		$this->controller->redirectBack();
		return false;
	}
}


/*


	if(count($supportedPaymentGateways)) {
			//header for payment options
			$paymentFieldsCollection->push(new HeaderField("ChoosePayment", _t("OrderForm.CHOOSE_PAYMENT", "Choose Payment")));
			foreach($supportedPaymentGateways as $paymentClassName) {
				$processor = PaymentFactory::factory($paymentClassName);
				if($processor) {
					$paymentFields = $processor->getFormFields();
					if($paymentFields) {
						foreach($paymentFields as $paymentField) {
							$paymentFieldsCollection->push($paymentField);
						}
					}
					$paymentFieldsRequired = $processor->getFormRequirements();
					if($paymentFieldsRequired) {
						foreach($paymentFieldsRequired as $paymentFieldRequired) {
							$paymentFieldsRequiredCollection[] = $paymentFieldRequired;
						}
					}
		if($order->Total() > 0) {
			$paymentFields = Payment::combined_form_fields($order->getTotalAsMoney()->NiceDefaultFormat(false));
			foreach($paymentFields as $paymentField) {
				if($paymentField->class == "HeaderField") {
					$paymentField->setTitle(_t("OrderForm.MAKEPAYMENT", "Choose Payment"));
				}
			}
		}

		if($paymentFieldsCollection) {
			$bottomFields->push($paymentFieldsCollection);
			$requiredFields = array_merge($requiredFields, $paymentFieldsRequiredCollection);
		}

*/
