<?php

/**
 * @description: The order class is a databound object for handling Orders within SilverStripe.
 * Note that it works closely with the ShoppingCart class, which accompanies the Order
 * until it has been paid for / confirmed by the user.
 *
 *
 * CONTENTS:
 * ----------------------------------------------
 * 1. CMS STUFF
 * 2. MAIN TRANSITION FUNCTIONS:
 * 3. STATUS RELATED FUNCTIONS / SHORTCUTS
 * 4. LINKING ORDER WITH MEMBER AND ADDRESS
 * 5. CUSTOMER COMMUNICATION
 * 6. ITEM MANAGEMENT
 * 7. CRUD METHODS (e.g. canView, canEdit, canDelete, etc...)
 * 8. GET METHODS (e.g. Total, SubTotal, Title, etc...)
 * 9. TEMPLATE RELATED STUFF
 * 10. STANDARD SS METHODS (requireDefaultRecords, onBeforeDelete, etc...)
 * 11. DEBUG
 *
 * @authors: Nicolaas [at] Sunny Side Up .co.nz
 * @package: ecommerce
 * @sub-package: model
 * @inspiration: Silverstripe Ltd, Jeremy
 *
 * NOTE: This is the SQL for selecting orders in sequence of
 *
 **/

class Order extends DataObject {

	/**
	 * API Control
	 * @var Array
	 */
	public static $api_access = array(
		'view' => array(
			'OrderEmail',
			'EmailLink',
			'PrintLink',
			'RetrieveLink',
			'Title',
			'Total',
			'SubTotal',
			'TotalPaid',
			'TotalOutstanding',
			'ExchangeRate',
			'CurrencyUsed',
			'TotalItems',
			'TotalItemsTimesQuantity',
			'IsCancelled',
			'Country' ,
			'FullNameCountry',
			'IsSubmitted',
			'CustomerStatus',
			'CanHaveShippingAddress',
			'CancelledBy',
			'CurrencyUsed',
			'BillingAddress',
			'UseShippingAddress',
			'ShippingAddress',
			'Status',
			'Attributes',
			'OrderStatusLogs',
			'MemberID'
		)
	);

	/**
	 * standard SS variable
	 * @var Array
	 */
	public static $db = array(
		'SessionID' => "Varchar(32)", //so that in the future we can link sessions with Orders.... One session can have several orders, but an order can onnly have one session
		'UseShippingAddress' => 'Boolean',
		'CustomerOrderNote' => 'Text',
		'ExchangeRate' => 'Double'
	);

	public static $has_one = array(
		'Member' => 'Member',
		'BillingAddress' => 'BillingAddress',
		'ShippingAddress' => 'ShippingAddress',
		'Status' => 'OrderStep',
		'CancelledBy' => 'Member',
		'CurrencyUsed' => 'EcommerceCurrency'
	);

	/**
	 * standard SS variable
	 * @var Array
	 */
	public static $has_many = array(
		'Attributes' => 'OrderAttribute',
		'OrderStatusLogs' => 'OrderStatusLog',
		'Payments' => 'EcommercePayment',
		'Emails' => 'OrderEmailRecord'
	);

	/**
	 * standard SS variable
	 * @var Array
	 */
	public static $indexes = array(
		"SessionID" => true
	);

	/**
	 * standard SS variable
	 * @var String
	 */
	public static $default_sort = "\"LastEdited\" DESC";

	/**
	 * standard SS variable
	 * @var Array
	 */
	public static $casting = array(
		'OrderEmail' => 'Text',
		'EmailLink' => 'Text',
		'PrintLink' => 'Text',
		'RetrieveLink' => 'Text',
		'Title' => 'Text',
		'Total' => 'Currency',
		'TotalAsMoney' => 'Money',
		'SubTotal' => 'Currency',
		'SubTotalAsMoney' => 'Money',
		'TotalPaid' => 'Currency',
		'TotalPaidAsMoney' => 'Money',
		'TotalOutstanding' => 'Currency',
		'TotalOutstandingAsMoney' => 'Money',
		'HasAlternativeCurrency' => 'Boolean',
		'TotalItems' => 'Int',
		'TotalItemsTimesQuantity' => 'Double',
		'IsCancelled' => 'Boolean',
		'Country' => 'Varchar(3)', //This is the applicable country for the order - for tax purposes, etc....
		'FullNameCountry' => 'Varchar',
		'IsSubmitted' => 'Boolean',
		'CustomerStatus' => 'Varchar',
		'CanHaveShippingAddress' => 'Boolean',
	);

	/**
	 * standard SS variable
	 * @var Array
	 */
	public static $create_table_options = array(
		'MySQLDatabase' => 'ENGINE=InnoDB'
	);

	/**
	 * standard SS variable
	 * @var String
	 */
	public static $singular_name = "Order";
		function i18n_singular_name() { return _t("Order.ORDER", "Order");}

	/**
	 * standard SS variable
	 * @var String
	 */
	public static $plural_name = "Orders";
		function i18n_plural_name() { return _t("Order.ORDERS", "Orders");}

	/**
	 * Standard SS variable.
	 * @var String
	 */
	public static $description = "A collection of items that together make up the 'Order'.  An order can be placed.";
		public static function reset_modifiers() {self::$modifiers = array();}

	/**
	 * Tells us if an order needs to be recalculated
	 * @var Boolean
	 */
	protected static $needs_recalculating = false;
		public static function set_needs_recalculating(){ self::$needs_recalculating = true;}
		public static function get_needs_recalculating(){ return self::$needs_recalculating;}

	/**
	 * Total Items : total items in cart
	 * We start with -1 to easily identify if it has been run before.
	 *
	 * @var integer
	 */
	protected $totalItems = null;

	/**
	 * Total Items : total items in cart
	 * We start with -1 to easily identify if it has been run before.
	 * @var Double
	 */
	protected $totalItemsTimesQuantity = null;


	public static function get_modifier_forms($controller) {
		user_error("this method has been changed to getModifierForms, the current function has been depreciated", E_USER_ERROR);
	}

	/**
	 * Returns a set of modifier forms for use in the checkout order form,
	 * Controller is optional, because the orderForm has its own default controller.
	 *
	 * This method only returns the Forms that should be included outside
	 * the editable table... Forms within it can be called
	 * from through the modifier itself.
	 *
	 * @param Controller $optionalController
	 * @param Validator $optionalValidator
	 * @return ArrayList (ModifierForms) | Null
	 **/
	public function getModifierForms(Controller $optionalController = null, Validator $optionalValidator = null) {
		$arrayList = new ArrayList();
		if (isset($_GET['debug_profile'])) Profiler::mark('Order::getModifierForms');
		$modifiers = $this->Modifiers();
		if($modifiers->count()) {
			foreach($modifiers as $modifier) {
				if($modifier->ShowForm()) {
					if($form = $modifier->getModifierForm($optionalController, $optionalValidator)) {
						$form->ShowFormInEditableOrderTable = $modifier->ShowFormInEditableOrderTable();
						$form->ShowFormOutsideEditableOrderTable = $modifier->ShowFormOutsideEditableOrderTable();
						$form->ModifierName = $modifier->ClassName;
						$arrayList->push($form);
					}
				}
			}
		}
		if (isset($_GET['debug_profile'])) Profiler::unmark('Order::getModifierForms');
		if( $arrayList->count() ) {
			return $arrayList;
		}
		else {
			return null;
		}
	}



	/**
	 * This function returns the OrderSteps
	 *
	 * @return ArrayList (OrderSteps)
	 **/
	public static function get_order_status_options() {
		return OrderStep::get();
	}

	/**
	 * Like the standard byID, but it checks whether we are allowed to view the order.
	 *
	 * @return: Order | Null
	 **/
	public static function get_by_id_if_can_view($id) {
		$order = Order::get()->byID($id);
		if($order && $order->canView()){
			if($order->IsSubmitted()) {
				// LITTLE HACK TO MAKE SURE WE SHOW THE LATEST INFORMATION!
				$order->tryToFinaliseOrder();
			}
			return $order;
		}
		return null;
	}

	/**
	 * returns a Datalist with the submitted order log included
	 * this allows you to sort the orders by their submit dates.
	 * You can retrieve this list and then add more to it (e.g. additional filters, additional joins, etc...)
	 * @param Boolean $onlySubmittedOrders - only include Orders that have already been submitted.
	 * @return DataList (Orders)
	 */
	public static function get_datalist_of_orders_with_submit_record($onlySubmittedOrders = false){
		$submittedOrderStatusLogClassName = EcommerceConfig::get("OrderStatusLog", "order_status_log_class_used_for_submitting_order");
		$list = Order::get()
			->LeftJoin("OrderStatusLog", "\"Order\".\"ID\" = \"OrderStatusLog\".\"OrderID\"")
			->LeftJoin($submittedOrderStatusLogClassName, "\"OrderStatusLog\".\"ID\" = \"".$submittedOrderStatusLogClassName."\".\"ID\"")
			->Sort("OrderStatusLog.Created", "ASC");
		if($onlySubmittedOrders) {
			$list = $list->Where("\"OrderStatusLog\".\"ClassName\" = '$submittedOrderStatusLogClassName'");
		}
		else {
			$list = $list->Where("\"OrderStatusLog\".\"ClassName\" = '$submittedOrderStatusLogClassName' OR \"OrderStatusLog\".\"ClassName\" IS NULL");
		}
		return $list;
	}



/*******************************************************
   * 1. CMS STUFF
*******************************************************/

	/**
	 * fields that we remove from the parent::getCMSFields object set
	 * @var Array
	 */
	protected $fieldsAndTabsToBeRemoved = array(
		'MemberID',
		'Attributes',
		'SessionID',
		'Emails',
		'BillingAddressID',
		'ShippingAddressID',
		'UseShippingAddress',
		'OrderStatusLogs',
		'Payments',
		'OrderDate',
		'ExchangeRate',
		'CurrencyUsedID',
		'StatusID',
		'Currency'
	);


	/**
	 * STANDARD SILVERSTRIPE STUFF
	 **/
	public static $summary_fields = array(
		"Title" => "Title",
		"Status.Title" => "Next Step"
	);
		public static function get_summary_fields() {return self::$summary_fields;}

	/**
	 * STANDARD SILVERSTRIPE STUFF
	 * @todo: how to translate this?
	 **/
	public static $searchable_fields = array(
		'ID' => array(
			'field' => 'NumericField',
			'title' => 'Order Number'
		),
		'MemberID' => array(
			'field' => 'TextField',
			'filter' => 'OrderFilters_MemberAndAddress',
			'title' => 'Customer Details'
		),
		'Created' => array(
			'field' => 'TextField',
			'filter' => 'OrderFilters_AroundDateFilter',
			'title' => 'Date (e.g. Today, 1 jan 2007, or last week)'
		),
		//make sure to keep the items below, otherwise they do not show in form
		'StatusID' => array(
			'filter' => 'OrderFilters_MultiOptionsetStatusIDFilter'
		),
		'CancelledByID' => array(
			'filter' => 'OrderFilters_HasBeenCancelled',
			'title' => "Cancelled"
		)
	);


	/**
	 * Determine which properties on the DataObject are
	 * searchable, and map them to their default {@link FormField}
	 * representations. Used for scaffolding a searchform for {@link ModelAdmin}.
	 *
	 * Some additional logic is included for switching field labels, based on
	 * how generic or specific the field type is.
	 *
	 * Used by {@link SearchContext}.
	 *
	 * @param array $_params
	 * 	'fieldClasses': Associative array of field names as keys and FormField classes as values
	 * 	'restrictFields': Numeric array of a field name whitelist
	 * @return FieldList
	 */
	public function scaffoldSearchFields($_params = null) {
		$fieldList = parent::scaffoldSearchFields($_params);
		$statusOptions = OrderStep::get();
		if($statusOptions && $statusOptions->count()) {
			$createdOrderStatusID = 0;
			$preSelected = array();
			$createdOrderStatus = $statusOptions->First();
			if($createdOrderStatus) {
				$createdOrderStatusID = $createdOrderStatus->ID;
			}
			$arrayOfStatusOptions = clone $statusOptions->map("ID", "Title")->toArray();
			$arrayOfStatusOptionsFinal = array();
			if(count($arrayOfStatusOptions)) {
				foreach($arrayOfStatusOptions as $key => $value) {
					$count = Order::get()
						->Filter(array("StatusID" => intval($key)))
						->count();
					if($count < 1) {
						//do nothing
					}
					else {
						$arrayOfStatusOptionsFinal[$key] = $value . " ($count)";
					}
					//we use 100 here because if there is such a big list, an additional filter should be added
					if($count > 100) {
					}
					else {
						if($key != $createdOrderStatusID) {
							$preSelected[$key] = $key;
						}
					}
				}
			}
			$statusField = new CheckboxSetField("StatusID", "Status", $arrayOfStatusOptionsFinal);
			$statusField->setValue($preSelected);
			$fieldList->push($statusField);
		}
		$fieldList->push(new DropdownField("CancelledByID", "Cancelled", array(-1 => "(Any)", 1 => "yes", 0 => "no")));
		return $fieldList;
	}

	/**
	 * @param String $action - e.g. edit
	 * @return String
	 */
	public function CMSEditLink($action = "") {
		$link = "/admin/sales/Order/EditForm/field/Order/item/".$this->ID."";
		if($action) {
			$link = $link . "/".$action;
		}
		return $link;
	}

	/**
	 * STANDARD SILVERSTRIPE STUFF
	 * broken up into submitted and not (yet) submitted
	 **/
	function getCMSFields(){
		$fields = parent::getCMSFields();
		if($this->exists()) {
			$submitted = $this->IsSubmitted() ? true : false;
			if($submitted) {
				//TODO
				//Having trouble here, as when you submit the form (for example, a payment confirmation)
				//as the step moves forward, meaning the fields generated are incorrect, causing an error
				//"I can't handle sub-URLs of a Form object." generated by the RequestHandler.
				//Therefore we need to try reload the page so that it will be requesting the correct URL to generate the correct fields for the current step
				//Or something similar.
				//why not check if the URL == $this->CMSEditLink()
				//and only tryToFinaliseOrder if this is true....
				if($_SERVER['REQUEST_URI'] == $this->CMSEditLink() || $_SERVER['REQUEST_URI'] == $this->CMSEditLink("edit")) {
					$this->tryToFinaliseOrder();
				}
			}
			else {
				$this->init(true);
			}
			if($submitted) {
				$this->fieldsAndTabsToBeRemoved[] = "CustomerOrderNote";
			}
			else {
				$this->fieldsAndTabsToBeRemoved[] = "Emails";
			}
			foreach($this->fieldsAndTabsToBeRemoved as $field) {
				$fields->removeByName($field);
			}
			$fields->insertAfter(
				new Tab(
					"Next",
					new HeaderField("MyOrderStepHeader", _t("Order.CURRENTSTATUS", "Current Status")),
					$this->OrderStepField(),
					new HeaderField("OrderStepNextStepHeader", _t("Order.ACTIONNEXTSTEP", "Action Next Step"), 1),
					new LiteralField("OrderStepNextStepHeaderExtra", "<p><strong>"._t("Order.NEEDTOREFRESH", "If you have made any changes to the order then you will have to refresh or save this record to see up-to-date options here.")."</strong></p>"),
					new LiteralField("ActionNextStepManually", "<br /><br /><br /><h3>"._t("Order.MANUALSTATUSCHANGE", "Manual Status Change")."</h3>")
					//SEE: $this->MyStep()->addOrderStepFields($fields, $this); BELOW
				),
				"Main"
			);
			if($submitted) {
				$htmlSummary = $this->renderWith("Order");
				$fields->addFieldToTab('Root.Main', new LiteralField('MainDetails', '<iframe src="'.$this->PrintLink().'" width="100%" height="500"></iframe>'));
				$fields->insertAfter(
					new Tab(
						"Emails",
						$this->getEmailsTableField()
					),
					"Next"
				);
				$fields->addFieldToTab('Root.Payments',$this->getPaymentsField());
				$fields->addFieldToTab("Root.Payments", new ReadOnlyField("TotalPaid", _t("Order.TOTALPAID", "Total Paid"), $this->getTotalPaid()));
				$fields->addFieldToTab("Root.Payments", new ReadOnlyField("TotalOutstanding", _t("Order.TOTALOUTSTANDING", "Total Outstanding"), $this->getTotalOutstanding()));
				if($this->canPay()) {
					$link = EcommercePaymentController::make_payment_link($this->ID);
					$js = "window.open(this.href, 'payment', 'toolbar=0,scrollbars=1,location=1,statusbar=1,menubar=0,resizable=1,width=800,height=600'); return false;";
					$header = _t("Order.MAKEPAYMENT", "make payment");
					$label = _t("Order.MAKEADDITIONALPAYMENTNOW", "make additional payment now");
					$linkHTML = '<a href="'.$link.'" onclick="'.$js.'">'.$label.'</a>';
					$fields->addFieldToTab("Root.Payments", new HeaderField("MakeAdditionalPaymentHeader", $header, 3));
					$fields->addFieldToTab("Root.Payments", new LiteralField("MakeAdditionalPayment", $linkHTML));
				}
				//member
				$member = $this->Member();
				if($member && $member->exists()) {
					$fields->addFieldToTab('Root.Account', new LiteralField("MemberDetails", $member->getEcommerceFieldsForCMS()));
				}
				else {
					$fields->addFieldToTab('Root.Customer', new LiteralField("MemberDetails",
						"<p>"._t("Order.NO_ACCOUNT","There is no account associated with this order")."</p>"
					));
				}
				$cancelledField = $fields->dataFieldByName("CancelledByID");
				$fields->removeByName("CancelledByID");
				$fields->addFieldToTab("Root.Cancellation", $cancelledField);
				$fields->addFieldToTab('Root.Log', $this->getOrderStatusLogsTableField_Archived());
				$submissionLog = $this->SubmissionLog();
				if($submissionLog) {
					$fields->addFieldToTab('Root.Log',
						new ReadonlyField(
							'SequentialOrderNumber',
							_t("Order.SEQUENTIALORDERNUMBER", "Sequential order number for submitted orders (e.g. 1,2,3,4,5...)"),
							$submissionLog->SequentialOrderNumber
						)
					);
				}
			}
			else {
				$linkText = _t(
					"Order.LOAD_THIS_ORDER",
					"load this order"
				);
				$message = _t(
					"Order.NOSUBMITTEDYET",
					"No details are shown here as this order has not been submitted yet. You can {link} to submit it... NOTE: For this, you will be logged in as the customer and logged out as (shop)admin .",
					array("link" => '<a href="'.$this->RetrieveLink().'" target="_blank">'.$linkText.'</a>')
				);
				$fields->addFieldToTab('Root.Main', new LiteralField('MainDetails', '<p>'.$message.'</p>'));
				$fields->addFieldToTab('Root.Items',$this->getOrderItemsField());
				$fields->addFieldToTab('Root.Extras', $this->getModifierTableField());

				//MEMBER STUFF
				$specialOptionsArray = array();
				if($this->MemberID) {
					$specialOptionsArray[0] =  _t("Order.SELECTCUSTOMER", "-- - Remover Customer -- -");
					$specialOptionsArray[$this->MemberID] =  _t("Order.LEAVEWITHCURRENTCUSTOMER", "- Leave with current customer: ").$this->Member()->getTitle();
				}
				elseif($currentMember = Member::currentUser()) {
					$specialOptionsArray[0] =  _t("Order.SELECTCUSTOMER", "-- - Select Customers -- -");
					$currentMemberID = $currentMember->ID;
					$specialOptionsArray[$currentMemberID] = _t("Order.ASSIGNTHISORDERTOME", "- Assign this order to me: ").$currentMember->getTitle();
				}
				//MEMBER FIELD!!!!!!!
				$memberArray = $specialOptionsArray + EcommerceRole::list_of_customers();
				$fields->addFieldToTab("Root.Main", new DropdownField("MemberID", _t("Order.SELECTCUSTOMER", "Select Customer"), $memberArray),"CustomerOrderNote");
				$memberArray = null;
			}
			$fields->addFieldToTab('Root.Addresses',new HeaderField("BillingAddressHeader", _t("Order.BILLINGADDRESS", "Billing Address")));


			$fields->addFieldToTab('Root.Addresses',$this->getBillingAddressField());

			if(EcommerceConfig::get("OrderAddress", "use_separate_shipping_address")) {
				$fields->addFieldToTab('Root.Addresses',new HeaderField("ShippingAddressHeader", _t("Order.SHIPPINGADDRESS", "Shipping Address")));
				$fields->addFieldToTab('Root.Addresses',new CheckboxField("UseShippingAddress", _t("Order.USESEPERATEADDRESS", "Use separate shipping address?")));
				if($this->UseShippingAddress) {
					$fields->addFieldToTab('Root.Addresses',$this->getShippingAddressField());
				}
			}
			$this->MyStep()->addOrderStepFields($fields, $this);
			$fields->addFieldToTab(
				"Root.Next",
				new LiteralField(
					"StatusIDExplanation",
					_t("Order.STATUSIDEXPLANATION", "You can not manually update the status of an order.").
					"<br /><br /><a href=\"".$this->CMSEditLink()."\">"._t("Order.REFRESH", "refresh order status")."</a>"
				)
			);
			$currencies = EcommerceCurrency::get_list();
			if($currencies && $currencies->count()) {
				$currencies = $currencies->map()->toArray();
				$fields->addFieldToTab("Root.Currency", new NumericField("ExchangeRate ", _t("Order.EXCHANGERATE", "Exchange Rate")));
				$fields->addFieldToTab("Root.Currency", new LookupField("CurrencyUsedID", _t("Order.CurrencyUsed", "Currency Used"), $currencies));
			}
			$fields->addFieldToTab("Root.Log", new ReadonlyField("Created", _t("Root.CREATED", "Created")));
			$fields->addFieldToTab("Root.Log", new ReadonlyField("LastEdited", _t("Root.LASTEDITED", "Last saved")));
		}
		else {
			$fields->removeByName("Main");
			$firstStep = OrderStep::get()->First();
			$msg = _t("Order.VERYFIRSTSTEP", "The first step in creating an order is to save (<i>add</i>) it.");
			$fields->addFieldToTab("Root.Next", new LiteralField("VeryFirstStep", "<p>".$msg."</p>"));
			if($firstStep) {
				$fields->addFieldToTab("Root.Next", new HiddenField("StatusID", $firstStep->ID, $firstStep->ID));
			}
		}
		$this->extend('updateSettingsFields',$fields);
		return $fields;
	}

	/**
	 * Field to add and edit Order Items
	 * @return GridField
	 */
	protected function getOrderItemsField(){
		$gridFieldConfig = GridFieldConfig_RecordEditor::create();
		$source = $this->OrderItems();
		return new GridField("OrderItems", _t("OrderItems.PLURALNAME", "Order Items"), $source , $gridFieldConfig);
	}

	/**
	 * Field to add and edit Modifiers
	 * @return GridField
	 */
	function getModifierTableField(){
		$gridFieldConfig = GridFieldConfig_RecordEditor::create();
		$source = $this->Modifiers();
		return new GridField("OrderModifiers", _t("OrderItems.PLURALNAME", "Order Items"), $source , $gridFieldConfig);
	}

	/**
	 *
	 *@return GridField
	 **/
	protected function getBillingAddressField(){
		$this->CreateOrReturnExistingAddress("BillingAddress");
		$gridFieldConfig = GridFieldConfig::create()->addComponents(
			new GridFieldToolbarHeader(),
			new GridFieldSortableHeader(),
			new GridFieldDataColumns(),
			new GridFieldPaginator(10),
			new GridFieldEditButton(),
			new GridFieldDetailForm()
		);
		//$source = $this->BillingAddress();
		$source = BillingAddress::get()->filter(array("OrderID" => $this->ID));
		return new GridField("BillingAddress", _t("BillingAddress.SINGULARNAME", "Billing Address"), $source , $gridFieldConfig);
	}


	/**
	 *
	 *@return GridField
	 **/
	protected function getShippingAddressField(){
		$this->CreateOrReturnExistingAddress("ShippingAddress");
		$gridFieldConfig = GridFieldConfig::create()->addComponents(
			new GridFieldToolbarHeader(),
			new GridFieldSortableHeader(),
			new GridFieldDataColumns(),
			new GridFieldPaginator(10),
			new GridFieldEditButton(),
			new GridFieldDetailForm()
		);
		//$source = $this->ShippingAddress();
		$source = ShippingAddress::get()->filter(array("OrderID" => $this->ID));
		return new GridField("ShippingAddress", _t("BillingAddress.SINGULARNAME", "Shipping Address"), $source , $gridFieldConfig);
	}

	/**
	 *
	 * @return GridField
	 */
	function getOldOrderStatusLogsField(){
		Deprecation::notice('3.0', 'Use order::getOrderStatusLogsTableField instead.');
		return $this->getOrderStatusLogsTableField();
	}

	/**
	 *
	 * @return GridField
	 * @deprecated
	 */
	function OrderStatusLogsTable($sourceClass = "OrderStatusLog", $title = "", FieldList $fieldList = null, FieldList $detailedFormFields = null){
		Deprecation::notice('3.0', 'Use order::getOrderStatusLogsTableField instead.');
		return $this->getOrderStatusLogsTableField($sourceClass, $title, $fieldList, $detailedFormFields);
	}

	/**
	 * Needs to be public because the OrderStep::getCMSFIelds accesses it.
	 * @param String $sourceClass
	 * @param String $title
	 * @param FieldList $fieldList (Optional)
	 * @param FieldList $detailedFormFields (Optional)
	 *
	 * @return GridField
	 **/
	public function getOrderStatusLogsTableField($sourceClass = "OrderStatusLog", $title = "", FieldList $fieldList = null, FieldList $detailedFormFields = null) {
		$gridFieldConfig = GridFieldConfig_RecordViewer::create()->addComponents(
			new GridFieldAddNewButton('toolbar-header-right'),
			new GridFieldDetailForm()
		);
		$title ? $title : $title = _t("OrderStatusLog.PLURALNAME", "Order Status Logs");
		$source = $this->OrderStatusLogs()->Filter(array("ClassName" => $sourceClass));
		$gf = new GridField($sourceClass, $title, $source , $gridFieldConfig);
		$gf->setModelClass($sourceClass);
		return $gf;
	}

	/**
	 * @param String $sourceClass
	 * @param String $title
	 * @param FieldList $fieldList (Optional)
	 * @param FieldList $detailedFormFields (Optional)
	 *
	 * @return GridField
	 **/
	protected function getOrderStatusLogsTableField_Archived($sourceClass = "OrderStatusLog", $title = "", FieldList $fieldList = null, FieldList $detailedFormFields = null) {
		$gridFieldConfig = GridFieldConfig_RecordViewer::create()->addComponents(
			new GridFieldDetailForm()
		);
		$title ? $title : $title = _t("OrderItem.PLURALNAME", "Order Log");
		$source = $this->OrderStatusLogs();
		return new GridField($sourceClass, $title, $source , $gridFieldConfig);
	}

	/**
	 *
	 * @return GridField
	 **/
	public function getEmailsTableField() {
		$gridFieldConfig = GridFieldConfig_RecordViewer::create()->addComponents(
			new GridFieldDetailForm()
		);
		return new GridField("Emails", _t("Order.CUSTOMER_EMAILS", "Customer Emails"), $this->Emails(), $gridFieldConfig);
	}

	/**
	 *
	 * @return GridField
	 */
	protected function getPaymentsField(){
		$gridFieldConfig = GridFieldConfig_RecordViewer::create()->addComponents(
			new GridFieldDetailForm(),
			new GridFieldEditButton()
		);
		return new GridField("Payments", _t("Order.PAYMENTS", "Payments"), $this->Payments(), $gridFieldConfig);
	}

	/**
	 * @return OrderStepField
	 */
	function OrderStepField() {
		return new OrderStepField($name = "MyOrderStep", $this, Member::currentUser());
	}






/*******************************************************
   * 2. MAIN TRANSITION FUNCTIONS
*******************************************************/

	/**
	 * init runs on start of a new Order (@see onAfterWrite)
	 * it adds all the modifiers to the orders and the starting OrderStep
	 *
	 * @param Boolean $recalculate
	 * @return DataObject (Order)
	 **/
	public function init($recalculate = false) {
		//to do: check if shop is open....
		if($this->StatusID || $recalculate) {
			if(!$this->StatusID) {
				$createdOrderStatus = OrderStep::get()->First();
				$this->StatusID = $createdOrderStatus->ID;
			}
			$createdModifiersClassNames = array();
			$modifiersAsArrayList = new ArrayList();
			$modifiers = $this->modifiersFromDatabase($includingRemoved = true);
			if($modifiers->count()) {
				foreach($modifiers as $modifier) {
					$modifiersAsArrayList->push($modifier);
				}
			}
			if($modifiersAsArrayList->count()) {
				foreach($modifiersAsArrayList as $modifier) {
					$createdModifiersClassNames[$modifier->ID] = $modifier->ClassName;
				}
			}
			else {

			}
			$modifiersToAdd = EcommerceConfig::get("Order", "modifiers");
			if(is_array($modifiersToAdd) && count($modifiersToAdd) > 0) {
				foreach($modifiersToAdd as $numericKey => $className) {
					if(!in_array($className, $createdModifiersClassNames)) {
						if(class_exists($className)) {
							$modifier = new $className();
							//only add the ones that should be added automatically
							if(!$modifier->DoNotAddAutomatically()) {
								if($modifier instanceof OrderModifier) {
									$modifier->OrderID = $this->ID;
									$modifier->Sort = $numericKey;
									//init method includes a WRITE
									$modifier->init();
									//IMPORTANT - add as has_many relationship  (Attributes can be a modifier OR an OrderItem)
									$this->Attributes()->add($modifier);
									$modifiersAsArrayList->push($modifier);
								}
							}
						}
						else{
							user_error("reference to a non-existing class: ".$className." in modifiers", E_USER_NOTICE);
						}
					}
				}
			}
			$this->extend('onInit', $this);
			//careful - this will call "onAfterWrite" again
			$this->write();
		}
		return $this;
	}


	/**
	 * Goes through the order steps and tries to "apply" the next status to the order.
	 *
	 **/
	public function tryToFinaliseOrder() {
		if($this->CancelledByID) {
			return;
		}
		do {
			//status of order is being progressed
			$nextStatusID = $this->doNextStatus();
			//a little hack to make sure we do not rely on a stored value
			//of "isSubmitted"
			$this->isSubmittedTempVar = -1;
		}
		while ($nextStatusID);
	}

	/**
	 * Goes through the order steps and tries to "apply" the next
	 * @return Integer (StatusID or false if the next status can not be "applied")
	 **/
	public function doNextStatus() {
		if($this->MyStep()->initStep($this)) {
			if($this->MyStep()->doStep($this)) {
				if($nextOrderStepObject = $this->MyStep()->nextStep($this)) {
					$this->StatusID = $nextOrderStepObject->ID;
					$this->write();
					return $this->StatusID;
				}
			}
		}
		return 0;
	}

	/**
	 * cancel an order.
	 * @param Member $member - the user cancelling the order
	 * @param String $reason - the reason the order is cancelled
	 * @returns OrderStatusLog_Cancel
	 */
	public function Cancel(Member $member, $reason = "") {
		$this->CancelledByID = $member->ID;
		$this->write();
		$log = new OrderStatusLog_Cancel();
		$log->AuthorID = $member->ID;
		$log->OrderID = $this->ID;
		$log->Note = $reason;
		if($member->IsShopAdmin()) {
			$log->InternalUseOnly = true;
		}
		return $log->write();
	}







/*******************************************************
   * 3. STATUS RELATED FUNCTIONS / SHORTCUTS
*******************************************************/

	/**
	 * @return DataObject (current OrderStep)
	 */
	public function MyStep() {
		$obj = null;
		if($this->StatusID) {
			$obj = OrderStep::get()->byID($this->StatusID);
		}
		if(!$obj) {
			$obj = OrderStep::get()->First(); //TODO: this could produce strange results
		}
		if(!$obj) {
			$obj = new OrderStep_Created();
		}
		return $obj;
	}

	/**
	 * @return OrderStatusLog
	 */
	public function RelevantLogEntry(){
		return $this->MyStep()->RelevantLogEntry($this);
	}

	/**
	 * @return DataObject (current OrderStep that can be seen by customer)
	 */
	public function CurrentStepVisibleToCustomer() {
		$obj = $this->MyStep();
		if($obj->HideStepFromCustomer) {
			$obj = OrderStep::get()->where("\"OrderStep\".\"Sort\" < ".$obj->Sort." AND \"HideStepFromCustomer\" = 0")->First();
			if(!$obj) {
				$obj = OrderStep::get()->First();
			}
		}
		return $obj;
	}

	/**
	 * works out if the order is still at the first OrderStep.
	 * @return boolean
	 */
	public function IsFirstStep() {
		$firstStep = OrderStep::get()->First();
		$currentStep = $this->MyStep();
		if($firstStep && $currentStep) {
			if($firstStep->ID == $currentStep->ID) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is the order still being "edited" by the customer?
	 * @return boolean
	 */
	function IsInCart(){
		return (bool)$this->IsSubmitted();
	}

	/**
	 * The order has "passed" the IsInCart phase
	 * @return boolean
	 */
	function IsPastCart(){
		return (bool) !$this->IsInCart();
	}

	/**
	* Are there still steps the order needs to go through?
	 * @return boolean
	 */
	function IsUncomplete() {
		return (bool)$this->MyStep()->ShowAsUncompletedOrder;
	}

	/**
	* Is the order in the :"processing" phaase.?
	 * @return boolean
	 */
	function IsProcessing() {
		return (bool)$this->MyStep()->ShowAsInProcessOrder;
	}

	/**
	* Is the order completed?
	 * @return boolean
	 */
	function IsCompleted() {
		return (bool)$this->MyStep()->ShowAsCompletedOrder;
	}

	/**
	 * Has the order been paid?
	 * TODO: why do we check if there is a total at all?
	 * @return boolean
	 */
	function IsPaid() {
		if($this->IsSubmitted()) {
			return (bool)( ($this->Total() >= 0) && ($this->TotalOutstanding() <= 0));
		}
		return false;
	}

	/**
	 * Has the order been cancelled?
	 * @return boolean
	 */
	public function IsCancelled() {return $this->getIsCancelled();}
	public function getIsCancelled() {
		return $this->CancelledByID ? TRUE : FALSE;
	}

	/**
	 * Has the order been cancelled by the customer?
	 * @return boolean
	 */
	function IsCustomerCancelled() {
		if($this->MemberID > 0 && $this->MemberID == $this->IsCancelledID) {
			return true;
		}
		return false;
	}


	/**
	 * Has the order been cancelled by the  administrator?
	 * @return boolean
	 */
	function IsAdminCancelled() {
		if($this->IsCancelled()) {
			if(!$this->IsCustomerCancelled()) {
				$admin = Member::get()->byID($this->CancelledByID);
				if($admin) {
					if($admin->IsShopAdmin()) {
						return true;
					}
				}
			}
		}
		return false;
	}


	/**
	* Is the Shop Closed for business?
	 * @return boolean
	 */
	function ShopClosed() {
		return EcomConfig()->ShopClosed;
	}









/*******************************************************
   * 4. LINKING ORDER WITH MEMBER AND ADDRESS
*******************************************************/


	/**
	 * Returns a member linked to the order.
	 * If a member is already linked, it will return the existing member.
	 * Otherwise it will return a new Member.
	 *
	 * Any new member is NOT written, because we dont want to create a new member unless we have to!
	 * We will not add a member to the order unless a new one is created in the checkout
	 * OR the member is logged in / logs in.
	 *
	 * Also note that if a new member is created, it is not automatically written
	 *
	 * @param Boolean $forceCreation - if set to true then the member will always be saved in the database.
	 * @return Member
	 **/
	public function CreateOrReturnExistingMember($forceCreation = false) {
		if($this->MemberID) {
			$member = $this->Member();
		}
		elseif($member = Member::currentUser()) {
			if(!$member->IsShopAdmin()) {
				$this->MemberID = $member->ID;
				$this->write();
			}
		}
		$member = $this->Member();
		if(!$member) {
			$member = new Member();
		}
		if($member && $forceCreation) {
			$member->write();
		}
		return $member;
	}

	/**
	 * Returns either the existing one or a new Order Address...
	 * All Orders will have a Shipping and Billing address attached to it.
	 * Method used to retrieve object e.g. for $order->BillingAddress(); "BillingAddress" is the method name you can use.
	 * If the method name is the same as the class name then dont worry about providing one.
	 *
	 * @param String $className   - ClassName of the Address (e.g. BillingAddress or ShippingAddress)
	 * @param String $alternativeMethodName  - method to retrieve Address
	 *
	 * @return Null | OrderAddress
	 **/

	public function CreateOrReturnExistingAddress($className = "BillingAddress", $alternativeMethodName = '') {
		if($this->exists()) {
			$variableName = $className."ID";
			$methodName = $className;
			if($alternativeMethodName) {
				$methodName = $alternativeMethodName;
			}
			$address = null;
			if($this->$variableName) {
				$address = $this->$methodName();
			}
			if(!$address) {
				$address = new $className();
				if($member = $this->CreateOrReturnExistingMember()) {
					if($member->exists()) {
						$address->FillWithLastAddressFromMember($member, $write = false);
					}
				}
			}
			if($address) {
				if(!$address->exists()) {
					$address->write();
				}
				if($address->OrderID != $this->ID) {
					$address->OrderID = $this->ID;
					$address->write();
				}
				if($this->$variableName != $address->ID){
					if(!$this->IsSubmitted()) {
						$this->$variableName = $address->ID;
						$this->write();
					}
				}
				return $address;
			}
		}
		return null;
	}

	/**
	 * Sets the country in the billing and shipping address
	 * TO DO: only set one or the other....
	 * @param String $countryCode - code for the country e.g. NZ
	 **/
	public function SetCountryFields($countryCode) {
		if($billingAddress = $this->CreateOrReturnExistingAddress("BillingAddress")) {
			$billingAddress->SetCountryFields($countryCode);
		}
		if(EcommerceConfig::get("OrderAddress", "use_separate_shipping_address")) {
			if($shippingAddress = $this->CreateOrReturnExistingAddress("ShippingAddress")) {
				$shippingAddress->SetCountryFields($countryCode);
			}
		}
	}

	/**
	 * Sets the region in the billing and shipping address
	 * @param Integer $regionID - ID for the region to be set
	 **/
	public function SetRegionFields($regionID) {
		if($billingAddress = $this->CreateOrReturnExistingAddress("BillingAddress")) {
			$billingAddress->SetRegionFields($regionID);
		}
		if($this->CanHaveShippingAddress()) {
			if($shippingAddress = $this->CreateOrReturnExistingAddress("ShippingAddress")) {
				$shippingAddress->SetRegionFields($regionID);
			}
		}
	}

	/**
	 * Stores the preferred currency of the order.
	 * IMPORTANTLY we store the exchange rate for future reference...
	 * @param EcommerceCurrency $currency
	 */
	public function UpdateCurrency($currency) {
		if($this->IsSubmitted()) {
			user_error("Can not set the exchange rate after the order has been submitted", E_USER_NOTICE);
		}
		else {
			$this->CurrencyUsedID = $currency->ID;
			$this->ExchangeRate = $currency->ExchangeRate();
			$this->write();
		}
	}




/*******************************************************
   * 5. CUSTOMER COMMUNICATION
*******************************************************/

	/**
	 * Send the invoice of the order by email.
	 *
	 * @param String $subject - subject for the email
	 * @param String $message - the main message in the email
	 * @param Boolean $resend - send the email even if it has been sent before
	 * @param Boolean $adminOnly - do not send to customer, only send to shop admin
	 * @param String $emailClassName - class used to send email
	 * @return Boolean TRUE on success, FALSE on failure (in theory)
	 */
	function sendEmail($subject = "", $message = "", $resend = false, $adminOnly = false, $emailClassName = 'Order_InvoiceEmail') {
		return $this->prepareEmail($emailClassName, $subject, $message, $resend, $adminOnly);
	}

	/**
	 * Sends a message to the shop admin ONLY and not to the customer
	 * This can be used by ordersteps and orderlogs to notify the admin of any potential problems.
	 *
	 * @param String $subject - subject for the email
	 * @param String $message - message to be added with the email
	 * @return Boolean TRUE for success, FALSE for failure (not tested)
	 */
	public function sendError($subject = "", $message = "") {
		return $this->prepareEmail('Order_ErrorEmail', _t("Order.ERROR", "ERROR")." ".$subject, $message, $resend = true, $adminOnly = true);
	}

	/**
	 * Sends a message to the shop admin ONLY and not to the customer
	 * This can be used by ordersteps and orderlogs to notify the admin of any potential problems.
	 *
	 * @param String $subject - subject for the email
	 * @param String $message - message to be added with the email
	 * @return Boolean TRUE for success, FALSE for failure (not tested)
	 */
	public function sendAdminNotification($subject = "", $message = "") {
		return $this->prepareEmail('Order_ErrorEmail', $subject, $message, $resend = false, $adminOnly = true);
	}

	/**
	 * Send a mail of the order to the client (and another to the admin).
	 *
	 * @param String $emailClassName - the class name of the email you wish to send
	 * @param String $subject - email subject
	 * @param Boolean $copyToAdmin - true by default, whether it should send a copy to the admin
	 * @param Boolean $resend - sends the email even it has been sent before.
	 * @param Boolean $adminOnly - sends the email to the ADMIN ONLY.
	 *
	 * @return Boolean TRUE for success, FALSE for failure (not tested)
	 */
	protected function prepareEmail($emailClassName, $subject, $message, $resend = false, $adminOnly = false) {
		$arrayData = $this->createReplacementArrayForEmail($message, $subject);
 		$from = Order_Email::get_from_email();
 		//why are we using this email and NOT the member.EMAIL?
 		//for historical reasons????
		if($adminOnly) {
			$to = Order_Email::get_from_email();
		}
		else {
			$to = $this->getOrderEmail();
		}
 		if($from && $to) {
			$email = new $emailClassName();
			if(!($email instanceOf Email)) {
				user_error("No correct email class provided.", E_USER_ERROR);
			}
			$email->setFrom($from);
			$email->setTo($to);
			//we take the subject from the Array Data, just in case it has been adjusted.
			$email->setSubject($arrayData->getField("Subject"));
			//we also see if a CC and a BCC have been added
			;
			if($cc = $arrayData->getField("CC")) {
				$email->setCc($cc);
			}
			if($bcc = $arrayData->getField("BCC")) {
				$email->setBcc($bcc);
			}
			$email->populateTemplate($arrayData);
			// This might be called from within the CMS,
			// so we need to restore the theme, just in case
			// templates within the theme exist
			$oldTheme = SSViewer::current_theme();
			SSViewer::set_theme(SSViewer::current_custom_theme());
			$email->setOrder($this);
			$email->setResend($resend);
			$result = $email->send(null);
			SSViewer::current_theme($oldTheme);
			return $result;
		}
		return false;
	}

	/**
	 * returns the Data that can be used in the body of an order Email
	 * we add the subject here so that the subject, for example, can be added to the <title>
	 * of the email template.
	 * @param String $message - the additional message
	 * @param String $subject - subject for email -
	 * @return ArrayData
	 * - Subject - EmailSubject
	 * - Message - specific message for this order
	 * - OrderStepMessage - generic message for step
	 * - Order
	 * - EmailLogo
	 * - ShopPhysicalAddress
	 * - CurrentDateAndTime
	 * - BaseURL
	 * - CC
	 * - BCC
	 */
	public function createReplacementArrayForEmail($message = "", $subject = ""){
		$step = $this->MyStep();
		$config = $this->EcomConfig();
		$replacementArray = array();
		if($subject) {
			$replacementArray["Subject"] = $subject;
		}
		else {
			$replacementArray["Subject"] = $step->EmailSubject;
		}
 		$replacementArray["To"] = "";
 		$replacementArray["CC"] = "";
 		$replacementArray["BCC"] = "";
 		$replacementArray["Message"] = $message;
 		$replacementArray["OrderStepMessage"] = $step->CustomerMessage;
		$replacementArray["Order"] = $this;
		$replacementArray["EmailLogo"] = $config->EmailLogo();
		$replacementArray["ShopPhysicalAddress"] = $config->ShopPhysicalAddress;
		$replacementArray["CurrentDateAndTime"] = DBField::create_field('SS_Datetime', "Now");
		$replacementArray["BaseURL"] = Director::baseURL();
		$arrayData = new ArrayData($replacementArray);
		$this->extend('updateReplacementArrayForEmail', $arrayData);
		return $arrayData;
	}

	/**
	 * returns the order formatted as an email
	 * @param String $message - the additional message
	 * @param String $emailClassName - template to use.
	 * @return array (Message, Order, EmailLogo, ShopPhysicalAddress)
	 */
	public function renderOrderInEmailFormat($message = "", $emailClassName) {
		$arrayData = $this->createReplacementArrayForEmail($message);
		$html = $arrayData->renderWith($emailClassName);
		return Order_Email::emogrify_html($html);
	}








/*******************************************************
   * 6. ITEM MANAGEMENT
*******************************************************/

	/**
	 * returns a list of Order Attributes by type
	 *
	 * @param Array | String $types
	 * @return ArrayList
	 */
	function getOrderAttributesByType($types){
		if(!is_array($types) && is_string($types)){
			$types = array($types);
		}
		if(!is_array($al)) {
			user_error("wrong parameter (types) provided in Order::getOrderAttributesByTypes");
		}
		$al = new ArrayList();
		$items = $this->Items();
		foreach($items as $item) {
			if(in_array($item->OrderAttributeType(), $types)){
				$al->push($item);
			}
		}
		$modifiers = $this->Modifiers();
		foreach($modifiers as $modifier) {
			if(in_array($modifier->OrderAttributeType(), $types)){
				$al->push($modifier);
			}
		}
		return $al;
	}

	/**
	 * Returns the items of the order.
	 * Items are the order items (products) and NOT the modifiers (discount, tax, etc...)
	 * @param String filter - where statement to exclude certain items OR ClassName (e.g. 'TaxModifier')
	 * @return DataList (OrderItems)
	 */
	function Items($filterOrClassName = "") {
 		if(!$this->exists()){
 			$this->write();
		}
		return $this->itemsFromDatabase($filterOrClassName);
	}

	/**
	 * @alias function of Items
	 * @param String filter - where statement to exclude certain items.
	 * @return DataList (OrderItems)
	 */
	function OrderItems($filterOrClassName = "") {
		return $this->Items($filterOrClassName);
	}

	/**
	 * Return all the {@link OrderItem} instances that are
	 * available as records in the database.
	 * @param String filter - where statement to exclude certain items,
	 *   you can also pass a classname (e.g. MyOrderItem), in which case only this class will be returned (and any class extending your given class)
	 * @return DataList (OrderItems)
	 */
	protected function itemsFromDatabase($filterOrClassName = "") {
		$className = "OrderItem";
		$extrafilter = "";
		if($filterOrClassName) {
			if(class_exists($filterOrClassName)) {
				$className = $filterOrClassName;
			}
			else {
				$extrafilter = " AND $filterOrClassName";
			}
		}
		return $className::get()->where("\"OrderAttribute\".\"OrderID\" = ".$this->ID." $extrafilter");
	}

	/**
	 * @alias for Modifiers
	 * @return DataList (OrderModifiers)
	 */
	public function OrderModifiers() {
		return $this->Modifiers();
	}

	/**
	 * Returns the modifiers of the order, if it hasn't been saved yet
	 * it returns the modifiers from session, if it has, it returns them
	 * from the DB entry. ONLY USE OUTSIDE ORDER
	 * @param String filter - where statement to exclude certain items OR ClassName (e.g. 'TaxModifier')
	 * @return DataList (OrderModifiers)
	 */
	public function Modifiers($filterOrClassName = '') {
		return $this->modifiersFromDatabase($filterOrClassName);
	}

	/**
	 * Get all {@link OrderModifier} instances that are
	 * available as records in the database.
	 * NOTE: includes REMOVED Modifiers, so that they do not get added again...
	 * @param String filter - where statement to exclude certain items OR ClassName (e.g. 'TaxModifier')
	 * @return DataList (OrderModifiers)
	 */
	protected function modifiersFromDatabase($filterOrClassName = '') {
		$className = "OrderModifier";
		$extrafilter = "";
		if($filterOrClassName) {
			if(class_exists($filterOrClassName)) {
				$className = $filterOrClassName;
			}
			else {
				$extrafilter = " AND $filterOrClassName";
			}
		}
		return $className::get()->where("\"OrderAttribute\".\"OrderID\" = ".$this->ID." $extrafilter");
	}

	/**
	 * Calculates and updates all the order attributes.
	 * @param Bool $recalculate - run it, even if it has run already
	 *
	 */
	public function calculateOrderAttributes($recalculate = false) {
		if($this->IsSubmitted()) {
			//submitted orders are NEVER recalculated.
			//they are set in stone.
		}
		elseif(Order::get_needs_recalculating() || $recalculate) {
			if($this->StatusID || $this->TotalItems()) {
				$this->calculateOrderItems($recalculate);
				$this->calculateModifiers($recalculate);
				$this->extend("onCalculateOrder");
			}
		}
	}


	/**
	 * Calculates and updates all the product items.
	 * @param Bool $recalculate - run it, even if it has run already
	 */
	public function calculateOrderItems($recalculate = false) {
		//check if order has modifiers already
		//check /re-add all non-removable ones
		//$start = microtime();
		$orderItems = $this->itemsFromDatabase();
		if($orderItems->count()) {
			foreach($orderItems as $orderItem){
				if($orderItem) {
					$orderItem->runUpdate($recalculate);
				}
			}
		}
		$this->extend("onCalculateOrderItems", $orderItems);
	}



	/**
	 * Calculates and updates all the modifiers.
	 *
	 * @param Boolean $recalculate - run it, even if it has run already
	 */
	public function calculateModifiers($recalculate = false) {
		$createdModifiers = $this->modifiersFromDatabase();
		if($createdModifiers->count()) {
			foreach($createdModifiers as $modifier){
				if($modifier) {
					$modifier->runUpdate($recalculate);
				}
			}
		}
		$this->extend("onCalculateModifiers", $createdModifiers);
	}


	/**
	 * Returns the subtotal of the modifiers for this order.
	 * If a modifier appears in the excludedModifiers array, it is not counted.
	 *
	 * @param string|array $excluded - Class(es) of modifier(s) to ignore in the calculation.
	 * @param Boolean $stopAtExcludedModifier  - when this flag is TRUE, we stop adding the modifiers when we reach an excluded modifier.
	 *
	 * @return Float
	 */
	function ModifiersSubTotal($excluded = null, $stopAtExcludedModifier = false) {
		if (isset($_GET['debug_profile'])) Profiler::mark('Order::ModifiersSubTotal');
		$total = 0;
		$modifiers = $this->Modifiers();
		if($modifiers->count()) {
			foreach($modifiers as $modifier) {
				if(!$modifier->IsRemoved()) { //we just double-check this...
					if(is_array($excluded) && in_array($modifier->ClassName, $excluded)) {
						if($stopAtExcludedModifier) {
							break;
						}
						//do the next modifier
						continue;
					}
					elseif(is_string($excluded) && ($modifier->ClassName == $excluded)) {
						if($stopAtExcludedModifier) {
							break;
						}
						//do the next modifier
						continue;
					}
					$total += $modifier->CalculationTotal();
				}
			}
		}
		if (isset($_GET['debug_profile'])) Profiler::unmark('Order::ModifiersSubTotal');
		return $total;
	}

	/**
	 *
	 * @param string|array $excluded - Class(es) of modifier(s) to ignore in the calculation.
	 * @param Boolean $stopAtExcludedModifier  - when this flag is TRUE, we stop adding the modifiers when we reach an excluded modifier.
	 *
	 * @return Currency (DB Object)
	 **/
	function ModifiersSubTotalAsCurrencyObject($excluded = null, $stopAtExcludedModifier = false) {
		return DBField::create_field('Currency',$this->ModifiersSubTotal($excluded, $stopAtExcludedModifier));
	}


	/**
	 * @param String $className: class name for the modifier
	 * @return DataObject (OrderModifier)
	 **/
	function RetrieveModifier($className) {
		$modifiers = $this->Modifiers();
		if($modifers->count()) {
			foreach($modifiers as $modifier) {
				if($modifier instanceof $className) {
					return $modifier;
				}
			}
		}
	}


/*******************************************************
   * 7. CRUD METHODS (e.g. canView, canEdit, canDelete, etc...)
*******************************************************/

	/**
	 * @param Member $member
	 * @return DataObject (Member)
	 **/
	 //TODO: please comment why we make use of this function
	protected function getMemberForCanFunctions(Member $member = null) {
		if(!$member) {$member = Member::currentUser();}
		if(!$member) {
			$member = new Member();
			$member->ID = 0;
		}
		return $member;
	}

	/**
	 * @param Member $member
	 * @return Boolean
	 **/
	public function canCreate($member = null) {
		$member = $this->getMemberForCanFunctions($member);
		$extended = $this->extendedCan('canCreate', $member->ID);
		if($extended !== null) {return $extended;}
		if($member->exists()) {
			return $member->IsShopAdmin();
		}
	}

	/**
	 * Standard SS method - can the current member view this order?
	 * @param Member $member
	 * @return Boolean
	 **/
	public function canView($member = null) {
		if(!$this->exists()) {
			return true;
		}
		$member = $this->getMemberForCanFunctions($member);
		//check if this has been "altered" in any DataExtension
		$extended = $this->extendedCan('canView', $member->ID);
		//if this method has been extended in a data object decorator then use this
		if($extended !== null) {
			return $extended;
		}
		//is the member is a shop admin they can always view it
		if(EcommerceRole::current_member_is_shop_admin($member)) {
			return true;
		}
		//if the current member OWNS the order, (s)he can always view it.
		if($member->exists() && $this->MemberID == $member->ID) {
			return true;
		}
		//it is the current order
		$currentOrder = ShoppingCart::current_order();
		if($currentOrder && $currentOrder->ID == $this->ID){
			//we do some additional CHECKS for session hackings!
			if($member->exists()) {
				//must be the same member!
				if($this->MemberID == $member->ID) {
					return true;
				}
				//order belongs to another member!
				elseif($this->MemberID) {
					return false;
				}
				//order does not belong to anyone yet! ADD IT NOW.
				else{
					//we do NOT add the member here, because this is done in shopping cart
					//$this->MemberID = $member->ID;
					//$this->write();
					return true;
				}
			}
			else{
				//order belongs to someone, but current user is NOT logged in...
				if($this->MemberID) {
					return false;
				}
				//no-one is logged in and order does not belong to anyone
				else {
					return true;
				}
			}
		}
		//if the session ID matches, we can always view it.
		//SECURITYL RISK: if you know someone else his/her session
		//OR you can view the sessions on the server
		//OR you can guess the session
		//THEN you can view the order.
		//by viewing the order you can also access some of the member details.
		//NB: this MUST be the last resort! If all other methods fail.
		//That is, if we are working with the current order then it is a good idea
		//to deny non-matching members.
		if( $this->SessionID && $this->SessionID == session_id()) {
			return true;
		}
		return false;
	}


	/**
	 * @param Member $member
	 * @return Boolean
	 **/
	function canEdit($member = null) {
		if($this->canView($member) && $this->MyStep()->CustomerCanEdit) {
			return true;
		}
		$member = $this->getMemberForCanFunctions($member);
		$extended = $this->extendedCan('canEdit', $member->ID);
		if($extended !== null) {return $extended;}

		if($member && $member->IsShopAdmin()) {
			return true;
		}

		if(!$this->canView($member) || $this->IsCancelled()) {
			return false;
		}

		return $this->MyStep()->CustomerCanEdit;
	}

	/**
	 * Can a payment be made for this Order?
	 * @param Member $member
	 * @return Boolean
	 **/
	function canPay(Member $member = null) {
		$member = $this->getMemberForCanFunctions($member);
		$extended = $this->extendedCan('canPay', $member->ID);
		if($extended !== null) {return $extended;}
		if($this->IsPaid() || $this->IsCancelled()) {
			return false;
		}
		return $this->MyStep()->CustomerCanPay;
	}

	/**
	 * Can the given member cancel this order?
	 * @param Member $member
	 * @return Boolean
	 **/
	function canCancel(Member $member = null) {
		//if it is already cancelled it can be cancelled again
		if($this->CancelledByID) {
			return false;
		}
		$member = $this->getMemberForCanFunctions($member);
		$extended = $this->extendedCan('canCancel', $member->ID);
		if($extended !== null) {return $extended;}
		if($member && $member->IsShopAdmin()) {
			return true;
		}
		return $this->MyStep()->CustomerCanCancel;
	}


	/**
	 * @param Member $member
	 * @return Boolean
	 **/
	public function canDelete($member = null) {
		$member = $this->getMemberForCanFunctions($member);
		$extended = $this->extendedCan('canDelete', $member->ID);
		if($extended !== null) {return $extended;}
		if($this->IsSubmitted()){
			return false;
		}
		elseif($member && $member->IsShopAdmin()) {
			return true;
		}
		return false;
	}


	/**
	 * Returns all the order logs that the current member can view
	 * i.e. some order logs can only be viewed by the admin (e.g. suspected fraud orderlog).
	 *
	 * @return ArrayList (OrderStatusLogs)
	 **/
	public function CanViewOrderStatusLogs() {
		$canViewOrderStatusLogs = new ArrayList();
		$logs = $this->OrderStatusLogs();
		foreach($logs as $log) {
			if($log->canView()) {
				$canViewOrderStatusLogs->push($log);
			}
		}
		return $canViewOrderStatusLogs;
	}

	/**
	 * returns all the logs that can be viewed by the customer.
	 * @return ArrayList (OrderStausLogs)
	 */
	function CustomerViewableOrderStatusLogs() {
		$customerViewableOrderStatusLogs = new ArrayList();
		$logs = $this->OrderStatusLogs();
		if($logs) {
			foreach($logs as $log) {
				if(!$log->InternalUseOnly) {
					$customerViewableOrderStatusLogs->push($log);
				}
			}
		}
		return $customerViewableOrderStatusLogs;
	}








/*******************************************************
   * 8. GET METHODS (e.g. Total, SubTotal, Title, etc...)
*******************************************************/

	/**
	 * returns the email to be used for customer communication
	 * @return String
	 */
	function OrderEmail(){return $this->getOrderEmail();}
	function getOrderEmail() {
		$email = "";
		if($this->BillingAddressID && $this->BillingAddress()) {
			$email = $this->BillingAddress()->Email;
		}
		if(!$email) {
			if($this->MemberID && $this->Member()) {
				$email = $this->Member()->Email;
			}
		}
		$this->extend('updateOrderEmail', $email);
		return $email;
	}

	/**
	 * Returns true if there is a prink or email link.
	 * @return Boolean
	 */
	function HasPrintOrEmailLink(){
		return $this->EmailLink() || $this->PrintLink();
	}
	/**
	 * returns the absolute link to the order that can be used in the customer communication (email)
	 * @return String
	 */
	function EmailLink($type = "Order_StatusEmail"){return $this->getEmailLink();}
	function getEmailLink($type = "Order_StatusEmail") {
		if(!isset($_REQUEST["print"])) {
			if($this->IsSubmitted()) {
				return Director::AbsoluteURL(OrderConfirmationPage::get_email_link($this->ID, $this->MyStep()->getEmailClassName(), $actuallySendEmail = true));
			}
		}
	}

	/**
	 * returns the absolute link to the order for printing
	 * @return String
	 */
	function PrintLink(){return $this->getPrintLink();}
	function getPrintLink() {
		if(!isset($_REQUEST["print"])) {
			if($this->IsSubmitted()) {
				return Director::AbsoluteURL(OrderConfirmationPage::get_order_link($this->ID))."?print=1";
			}
		}
	}

	/**
	 * returns the absolute link to the order for printing
	 * @return String
	 */
	function PackingSlipLink(){return $this->getPackingSlipLink();}
	function getPackingSlipLink() {
		$member = Member::currentUser();
		if($member && $member->IsShopAdmin()) {
			if($this->IsSubmitted() ) {
				return Director::AbsoluteURL(OrderConfirmationPage::get_order_link($this->ID))."?packingslip=1";
			}
		}
	}


	/**
	 * returns the absolute link that the customer can use to retrieve the email WITHOUT logging in.
	 * @todo: is this a security risk?
	 * @return String
	 */
	function RetrieveLink(){return $this->getRetrieveLink();}
	function getRetrieveLink() {
		if($this->IsSubmitted()) {
			if(!$this->SessionID) {
				$this->SessionID = session_id();
				$this->write();
			}
			return Director::AbsoluteURL(OrderConfirmationPage::find_link())."retrieveorder/".$this->SessionID."/".$this->ID."/";
		}
		else {
			return Director::AbsoluteURL("/shoppingcart/loadorder/".$this->ID."/");
		}
	}

	/**
	 * link to delete order.
	 * @return String
	 */
	function DeleteLink(){return $this->getDeleteLink();}
	function getDeleteLink() {
		if($this->canDelete()) {
			return ShoppingCart_Controller::delete_order_link($this->ID);
		}
		else {
			return "";
		}
	}

	/**
	 * link to copy order.
	 * @return String
	 */
	function CopyOrderLink(){return $this->getCopyOrderLink();}
	function getCopyOrderLink() {
		if($this->canView()) {
			return ShoppingCart_Controller::copy_order_link($this->ID);
		}
		else {
			return "";
		}
	}

	/**
	 * A "Title" for the order, which summarises the main details (date, and customer) in a string.
	 * @return String
	 **/
	function Title($dateFormat = "D j M Y, G:i T", $includeName = true) {return $this->getTitle($dateFormat, $includeName);}
	function getTitle($dateFormat = "D j M Y, G:i T", $includeName = true) {
		if($this->exists()) {
			if($submissionLog = $this->SubmissionLog()) {
				$dateObject = $submissionLog->dbObject('Created');
				$placed = _t("Order.PLACED", "placed");
			}
			else {
				$dateObject = $this->dbObject('Created');
				$placed = _t("Order.STARTED", "started");
			}
			$title = $this->i18n_singular_name(). " #$this->ID - ".$placed." ".$dateObject->Format($dateFormat);
			$name = "";
			if($this->CancelledByID) {
				$name = " - "._t("Order.CANCELLED","CANCELLED");
			}
			if($includeName) {
				$by = _t("Order.BY", "by");
				if(!$name) {
					if($this->BillingAddressID) {
						if($billingAddress = $this->BillingAddress()) {
							$name = " - ".$by." ".$billingAddress->Prefix." ".$billingAddress->FirstName." ".$billingAddress->Surname;
						}
					}
				}
				if(!$name) {
					if($this->MemberID){
						if($member = $this->Member()) {
							if($member->exists()) {
								if($memberName = $member->getName()) {
									if(!trim($memberName)) {
										$memberName = _t("Order.ANONYMOUS","anonymous");
									}
									$name = " - ".$by." ".$memberName;
								}
							}
						}
					}
				}
				$title .= $name;
			}
		}
		else {
			$title = _t("Order.NEW", "New")." ".$this->i18n_singular_name();
		}
		$this->extend('updateTitle', $title);
		return $title;
	}



	/**
	 * Returns the subtotal of the items for this order.
	 * @return float
	 */
	function SubTotal(){return $this->getSubTotal();}
	function getSubTotal() {
		if (isset($_GET['debug_profile'])) Profiler::mark('Order::SubTotal');
		$result = 0;
		$items = $this->Items();
		if($items->count()) {
			foreach($items as $item) {
				if($item instanceOf OrderAttribute) {
					$result += $item->Total();
				}
			}
		}
		if (isset($_GET['debug_profile'])) Profiler::unmark('Order::SubTotal');
		return $result;
	}

	/**
	 *
	 * @return Currency (DB Object)
	 **/
	function SubTotalAsCurrencyObject() {
		return DBField::create_field('Currency',$this->SubTotal());
	}

	/**
	 *
	 * @return Money
	 **/
	function SubTotalAsMoney() {return $this->getSubTotalAsMoney();}
	function getSubTotalAsMoney() {
		return EcommerceCurrency::get_money_object_from_order_currency($this->SubTotal(), $this);
	}


	/**
	 * Returns the total cost of an order including the additional charges or deductions of its modifiers.
	 * @return float
	 */
	function Total() {return $this->getTotal();}
	function getTotal() {
		return $this->SubTotal() + $this->ModifiersSubTotal();
	}

	/**
	 *
	 * @return Currency (DB Object)
	 **/
	function TotalAsCurrencyObject() {
		return DBField::create_field('Currency',$this->Total());
	}

	/**
	 *
	 * @return Money
	 **/
	function TotalAsMoney() {return $this->getTotalAsMoney();}
	function getTotalAsMoney() {
		return EcommerceCurrency::get_money_object_from_order_currency($this->Total(), $this);
	}


	/**
	 * Checks to see if any payments have been made on this order
	 * and if so, subracts the payment amount from the order
	 *
	 * @return float
	 **/
	function TotalOutstanding() {return $this->getTotalOutstanding();}
	function getTotalOutstanding() {
		if($this->IsSubmitted()) {
			$total = $this->Total();
			$paid = $this->TotalPaid();
			$outstanding = $total - $paid;
			$maxDifference = EcommerceConfig::get("Order", "maximum_ignorable_sales_payments_difference");
			if(abs($outstanding) < $maxDifference) {
				$outstanding = 0;
			}
			return floatval($outstanding);
		}
		else {
			return 0;
		}
	}

	/**
	 *
	 * @return Currency (DB Object)
	 **/
	function TotalOutstandingAsCurrencyObject(){
		return DBField::create_field('Currency',$this->TotalOutstanding());
	}

	/**
	 *
	 * @return Money
	 **/
	function TotalOutstandingAsMoney() {return $this->getTotalOutstandingAsMoney();}
	function getTotalOutstandingAsMoney() {
		return EcommerceCurrency::get_money_object_from_order_currency($this->TotalOutstanding(), $this);
	}


	/**
	 * @return float
	 */
	function TotalPaid(){return $this->getTotalPaid();}
	function getTotalPaid() {
		$paid = 0;
		if($payments = $this->Payments()) {
			foreach($payments as $payment) {
				if($payment->Status == 'Success') {
					$paid += $payment->Amount->getAmount();
				}
			}
		}
		$reverseExchange = 1;
		if($this->ExchangeRate && $this->ExchangeRate != 1) {
			$reverseExchange = 1/$this->ExchangeRate;
		}
		return $paid * $reverseExchange;
	}

	/**
	 *
	 * @return Currency (DB Object)
	 **/
	function TotalPaidAsCurrencyObject(){
		return DBField::create_field('Currency',$this->TotalPaid());
	}

	/**
	 *
	 * @return Money
	 **/
	function TotalPaidAsMoney() {return $this->getTotalPaidAsMoney();}
	function getTotalPaidAsMoney() {
		return EcommerceCurrency::get_money_object_from_order_currency($this->TotalPaid(), $this);
	}


	/**
	 * returns the total number of OrderItems (not modifiers).
	 * This is meant to run as fast as possible to quickly check
	 * if there is anything in the cart.
	 *
	 * @param Boolean $recalculate - do we need to recalculate (value is retained during lifetime of Object)
	 * @return Integer
	 **/
	public function TotalItems($recalculate = false){return $this->getTotalItems($recalculate);}
	public function getTotalItems($recalculate = false) {
		if($this->totalItems === null || $recalculate) {
			$this->totalItems = OrderItem::get()
				->where("\"OrderAttribute\".\"OrderID\" = ".$this->ID." AND \"OrderItem\".\"Quantity\" > 0")
				->count();
		}
		return $this->totalItems;
	}

	/**
	 * Little shorthand
	 * @param Boolean $recalculate
	 * @return Boolean
	 **/
	public function MoreThanOneItemInCart($recalculate = false) {
		return $this->TotalItems($recalculate) > 1 ? true : false;
	}

	/**
	 * returns the total number of OrderItems (not modifiers) times their respectective quantities.
	 *
	 * @param Boolean $recalculate - force recalculation
	 * @return Double
	 **/
	public function TotalItemsTimesQuantity($recalculate = false){return $this->getTotalItemsTimesQuantity($recalculate);}
	public function getTotalItemsTimesQuantity($recalculate = false) {
		if($this->totalItemsTimesQuantity === null || $recalculate) {
			//to do, why do we check if you can edit ????
			$this->totalItemsTimesQuantity = DB::query("
				SELECT SUM(\"OrderItem\".\"Quantity\")
				FROM \"OrderItem\"
					INNER JOIN \"OrderAttribute\" ON \"OrderAttribute\".\"ID\" = \"OrderItem\".\"ID\"
				WHERE
					\"OrderAttribute\".\"OrderID\" = ".$this->ID."
					AND \"OrderItem\".\"Quantity\" > 0"
			)->value();
		}
		return $this->totalItemsTimesQuantity-0;
	}

	/**
	 * Returns the country code for the country that applies to the order.
	 * It only takes into account what has actually been saved.
	 * @return String (country code)
	 **/
	public function Country() {return $this->getCountry();}
	public function getCountry() {
		$countryCodes = array(
			"Billing" =>  "",
			"Shipping" => ""
		);
		if($this->BillingAddressID) {
			$billingAddress = BillingAddress::get()->byID($this->BillingAddressID);
			if($billingAddress) {
				if($billingAddress->Country) {
					$countryCodes["Billing"] = $billingAddress->Country;
				}
			}
		}
		if($this->ShippingAddressID && $this->UseShippingAddress) {
			$shippingAddress = BillingAddress::get()->byID($this->ShippingAddressID);
			if($shippingAddress) {
				if($shippingAddress->ShippingCountry) {
					$countryCodes["Shipping"] = $shippingAddress->ShippingCountry;
				}
			}
		}
		if(
			(EcommerceConfig::get("OrderAddress", "use_shipping_address_for_main_region_and_country") && $countryCodes["Shipping"])
			||
			(!$countryCodes["Billing"] && $countryCodes["Shipping"])
		) {
			return $countryCodes["Shipping"];
		}
		elseif($countryCodes["Billing"]) {
			return $countryCodes["Billing"];
		}
		else {
			return EcommerceCountry::get_country_from_ip();
		}
	}

	/**
	 * returns name of coutry
	 * @return String - country name
	 **/
	public function FullNameCountry() {return $this->getFullNameCountry();}
	public function getFullNameCountry() {
		return EcommerceCountry::find_title($this->Country());
	}

	/**
	 * returns name of coutry that we expect the customer to have
	 * This takes into consideration more than just what has been entered
	 * for example, it looks at GEO IP
	 * @todo: why do we dont return a string IF there is only one item.
	 * @return String - country name
	 **/
	public function ExpectedCountryName() {return $this->getExpectedCountryName();}
	public function getExpectedCountryName() {
		return EcommerceCountry::find_title(EcommerceCountry::get_country());
	}

	/**
	 * return the title of the fixed country (if any)
	 * @return String | empty string
	 **/
	public function FixedCountry() {return $this->getFixedCountry();}
	public function getFixedCountry() {
		$code = EcommerceCountry::get_fixed_country_code();
		if($code){
			return EcommerceCountry::find_title($code);
		}
		return "";
	}


	/**
	 * Returns the region that applies to the order.
	 * we check both billing and shipping, in case one of them is empty.
	 * @return DataObject | Null (EcommerceRegion)
	 **/
	function Region(){return $this->getRegion();}
	public function getRegion() {
		$regionIDs = array(
			"Billing" => 0,
			"Shipping" => 0
		);
		if($this->BillingAddressID) {
			if($billingAddress = $this->BillingAddress()) {
				if($billingAddress->RegionID) {
					$regionIDs["Billing"] = $billingAddress->RegionID;
				}
			}
		}
		if($this->CanHaveShippingAddress()) {
			if($this->ShippingAddressID) {
				if($shippingAddress = $this->ShippingAddress()) {
					if($shippingAddress->ShippingRegionID) {
						$regionIDs["Shipping"] = $shippingAddress->ShippingRegionID;
					}
				}
			}
		}
		if(count($regionIDs)) {
			//note the double-check with $this->CanHaveShippingAddress() and get_use_....
			if($this->CanHaveShippingAddress() && EcommerceConfig::get("OrderAddress", "use_shipping_address_for_main_region_and_country") && $regionIDs["Shipping"]) {
				return EcommerceRegion::get()->byID($regionIDs["Shipping"]);
			}
			else {
				return EcommerceRegion::get()->byID($regionIDs["Billing"]);
			}
		}
	}

	/**
	 * Casted variable - has the order been submitted?
	 * Currency is not the same as the standard one.
	 * @return Boolean
	 **/
	function HasAlternativeCurrency(){return $this->getHasAlternativeCurrency();}
	function getHasAlternativeCurrency() {
		if($currency = $this->CurrencyUsed()) {
			if(!$currency->IsDefault()) {
				if(!$this->ExchangeRate) {
					user_error("Order is using alternative currency without exchange rate record.", E_USER_NOTICE);
				}
				return true;
			}
		}
		return false;
	}

	/**
	 * speeds up processing by storing the IsSubmitted value
	 * we start with -1 to know if it has been requested before.
	 * @var Boolean
	 */
	protected $isSubmittedTempVar = -1;

	/**
	 * Casted variable - has the order been submitted?
	 * @param Boolean $recalculate
	 * @return Boolean
	 **/
	function IsSubmitted($recalculate = false){return $this->getIsSubmitted($recalculate);}
	function getIsSubmitted($recalculate = false) {
		if($this->isSubmittedTempVar == -1 || $recalculate) {
			if($this->SubmissionLog()) {
				$this->isSubmittedTempVar = true;
			}
			else {
				$this->isSubmittedTempVar = false;
			}
		}
		return $this->isSubmittedTempVar;
	}


	/**
	 * Submission Log for this Order (if any)
	 *
	 * @return Submission Log (OrderStatusLog_Submitted) | Null
	 **/
	public function SubmissionLog(){
		$className = EcommerceConfig::get("OrderStatusLog", "order_status_log_class_used_for_submitting_order");
		return $className::get()
			->Filter(array("OrderID" => $this->ID))
			->First();
	}

	/**
	 * Casted variable - has the order been submitted?
	 * @param Boolean $withDetail
	 * @return String
	 **/
	function CustomerStatus($withDetail = true){return $this->getCustomerStatus($withDetail);}
	function getCustomerStatus($withDetail = true) {
		if($this->MyStep()->ShowAsUncompletedOrder) { $v =  _t("Order.UNCOMPLETED", "Uncompleted");}
		elseif($this->MyStep()->ShowAsInProcessOrder) { $v = _t("Order.IN_PROCESS", "In Process");}
		elseif($this->MyStep()->ShowAsCompletedOrder) { $v = _t("Order.UNCOMPLETED", "Uncompleted");}
		if(!$this->HideStepFromCustomer && $withDetail) {
			$v .= ' ('.$this->MyStep()->Name.')';
		}
		return $v;
	}


	/**
	 * Casted variable - does the order have a potential shipping address?
	 *
	 * @return Boolean
	 **/
	function CanHaveShippingAddress() {return $this->getCanHaveShippingAddress();}
	function getCanHaveShippingAddress() {
		return EcommerceConfig::get("OrderAddress", "use_separate_shipping_address");
	}



	/**
	 * returns the link to view the Order
	 * WHY NOT CHECKOUT PAGE: first we check for cart page.
	 * @return CartPage | Null
	 */
	function DisplayPage() {
		if($this->MyStep() && $this->MyStep()->AlternativeDisplayPage()) {
			$page = $this->MyStep()->AlternativeDisplayPage();
		}
		elseif($this->IsSubmitted()) {
			$page = OrderConfirmationPage::get()->First();
		}
		else {
			$page = CartPage::get()
				->Filter(array("ClassName" => 'CartPage'))
				->First();
			if(!$page) {
				$page = CheckoutPage::get()->First();
			}
		}
		return $page;
	}

	/**
	 * returns the link to view the Order
	 * WHY NOT CHECKOUT PAGE: first we check for cart page.
	 * If a cart page has been created then we refer through to Cart Page.
	 * Otherwise it will default to the checkout page
	 * @param String $action - any action that should be added to the link.
	 * @return String(URLSegment)
	 */
	function Link($action = "") {
		$page = $this->DisplayPage();
		if($page) {
			return $page->getOrderLink($this->ID);
		}
		else {
			user_error("A Cart / Checkout Page + an Order Confirmation Page needs to be setup for the e-commerce module to work.", E_USER_NOTICE);
			$page = ErrorPage::get()
				->Filter(array("ErrorCode" => '404'))
				->First();
			if($page) {
				return $page->Link($action);
			}
		}
	}

	/**
	 * Returns to link to access the Order's API
	 * @param String $version
	 * @param String $extension
	 * @return String(URL)
	 */
	function APILink($version = "v1", $extension = "xml"){
		return Director::AbsoluteURL("/api/ecommerce/$version/Order/".$this->ID."/.$extension");
	}

	/**
	 * returns the link to finalise the Order
	 * @return String(URLSegment)
	 */
	function CheckoutLink() {
		$page = CheckoutPage::get()->First();
		if($page) {
			return $page->Link();
		}
		else {
			$page = ErrorPage::get()
				->Filter(array("ErrorCode" => '404'))
				->First();
			if($page) {
				return $page->Link();
			}
		}
	}

	/**
	 * Converts the Order into HTML, based on the Order Template.
	 * @return String - HTML
	 **/
	public function ConvertToHTML() {
		return $this->renderWith("Order");
	}

	/**
	 * Converts the Order into a serialized string
	 * TO DO: check if this works and check if we need to use special sapphire serialization code
	 * @return String - serialized object
	 **/
	public function ConvertToString() {
		return serialize($this->addHasOneAndHasManyAsVariables());
	}

	/**
	 * Converts the Order into a JSON object
	 * TO DO: check if this works and check if we need to use special sapphire JSON code
	 * @return String -  JSON
	 **/
	public function ConvertToJSON() {
		return json_encode($this->addHasOneAndHasManyAsVariables());
	}


	/**
	 * returns itself wtih more data added as variables.
	 * We add has_one and has_many as variables like this: $this->MyHasOne_serialized = serialize($this->MyHasOne())
	 * @return Order - with most important has one and has many items included as variables.
	 **/
	protected function addHasOneAndHasManyAsVariables() {
		/*
			THIS HAS TO BE REDONE - IT IS NONSENSICAL!
		$this->Member_serialized = serialize($this->Member());
		$this->BillingAddress_serialized = serialize($this->BillingAddress());
		$this->ShippingAddress_serialized = serialize($this->ShippingAddress());
		$this->Attributes_serialized = serialize($this->Attributes());
		$this->OrderStatusLogs_serialized = serialize($this->OrderStatusLogs());
		$this->Payments_serialized = serialize($this->Payments());
		$this->Emails_serialized = serialize($this->Emails());
		$this->Title_serialized = serialize($this->Title());
		$this->Total_serialized = serialize($this->Total());
		$this->SubTotal_serialized = serialize($this->SubTotal());
		$this->TotalPaid_serialized = serialize($this->TotalPaid());
		*/

		return $this;
	}




/*******************************************************
   * 9. TEMPLATE RELATED STUFF
*******************************************************/

	/**
	 * returns the instance of EcommerceConfigAjax for use in templates.
	 * In templates, it is used like this:
	 * $EcommerceConfigAjax.TableID
	 *
	 * @return EcommerceConfigAjax
	 **/
	public function AJAXDefinitions() {
		return EcommerceConfigAjax::get_one($this);
	}

	/**
	 * returns the instance of EcommerceDBConfig
	 *
	 * @return EcommerceDBConfig
	 **/
	public function EcomConfig(){
		return EcommerceDBConfig::current_ecommerce_db_config();
	}

	/**
	 * Collects the JSON data for an ajax return of the cart.
	 * @param Array $js
	 * @return Array (for use in AJAX for JSON)
	 **/
	function updateForAjax(array $js) {
		$function = EcommerceConfig::get('Order', 'ajax_subtotal_format');
		if(is_array($function)) {
			list($function, $format) = $function;
		}
		$subTotal = $this->$function();
		if(isset($format)) {
			$subTotal = $subTotal->$format();
			unset($format);
		}
		$function = EcommerceConfig::get('Order', 'ajax_total_format');
		if(is_array($function)) {
			list($function, $format) = $function;
		}
		$total = $this->$function();
		if(isset($format)) {
			$total = $total->$format();
		}
		$ajaxObject = $this->AJAXDefinitions();
		$js[] = array(
			't' => 'id',
			's' => $ajaxObject->TableSubTotalID(),
			'p' => 'innerHTML',
			'v' => $subTotal
		);
		$js[] = array(
			't' => 'id',
			's' => $ajaxObject->TableTotalID(),
			'p' => 'innerHTML',
			'v' => $total
		);
		$js[] = array(
			't' => 'class',
			's' => $ajaxObject->TotalItemsClassName(),
			'p' => 'innerHTML',
			'v' => $this->TotalItems($recalculate = true)
		);
		$js[] = array(
			't' => 'class',
			's' => $ajaxObject->TotalItemsTimesQuantityClassName(),
			'p' => 'innerHTML',
			'v' => $this->TotalItemsTimesQuantity()
		);
		$js[] = array(
			't' => 'class',
			's' => $ajaxObject->ExpectedCountryClassName(),
			'p' => 'innerHTML',
			'v' => $this->ExpectedCountryName()
		);
		return $js;
	}

	/**
	 * @ToDO: move to more appropriate class
	 * @return Float
	 **/
	public function SubTotalCartValue() {
		return $this->SubTotal;
	}






/*******************************************************
   * 10. STANDARD SS METHODS (requireDefaultRecords, onBeforeDelete, etc...)
*******************************************************/

	/**
	 *standard SS method
	 *
	 **/
	function populateDefaults() {
		parent::populateDefaults();
		if(!$this->SessionID) {
			$this->SessionID = session_id();
		}
	}

	function onBeforeWrite() {
		parent::onBeforeWrite();
		if(! $this->CurrencyUsedID) {
			$this->CurrencyUsedID = EcommerceCurrency::default_currency_id();
		}
	}

	/**
	 * standard SS method
	 * adds the ability to update order after writing it.
	 **/
	function onAfterWrite() {
		parent::onAfterWrite();
		//crucial!
		self::set_needs_recalculating();
		if($this->IsSubmitted($recalculate = true)) {
			//do nothing
		}
		else {
			if($this->StatusID) {
				$this->calculateOrderAttributes($recalculate = false);
				if(EcommerceRole::current_member_is_shop_admin()){
					if(isset($_REQUEST["SubmitOrderViaCMS"])) {
						$this->tryToFinaliseOrder();
						//just in case it writes again...
						unset($_REQUEST["SubmitOrderViaCMS"]);
					}
				}
			}
		}
	}

	/**
	 *standard SS method
	 *
	 * delete attributes, statuslogs, and payments
	 * THIS SHOULD NOT BE USED AS ORDERS SHOULD BE CANCELLED NOT DELETED
	 */
	function onBeforeDelete(){
		parent::onBeforeDelete();
		if($attributes = $this->Attributes()){
			foreach($attributes as $attribute){
				$attribute->delete();
				$attribute->destroy();
			}
		}

		//THE REST WAS GIVING ERRORS - POSSIBLY DUE TO THE FUNNY RELATIONSHIP (one-one, two times...)
		/*
		if($billingAddress = $this->BillingAddress()) {
			if($billingAddress->exists()) {
				$billingAddress->delete();
				$billingAddress->destroy();
			}
		}
		if($shippingAddress = $this->ShippingAddress()) {
			if($shippingAddress->exists()) {
				$shippingAddress->delete();
				$shippingAddress->destroy();
			}
		}

		if($statuslogs = $this->OrderStatusLogs()){
			foreach($statuslogs as $log){
				$log->delete();
				$log->destroy();
			}
		}
		if($payments = $this->Payments()){
			foreach($payments as $payment){
				$payment->delete();
				$payment->destroy();
			}
		}
		if($emails = $this->Emails()) {
			foreach($emails as $email){
				$email->delete();
				$email->destroy();
			}
		}
		*/

	}






/*******************************************************
   * 11. DEBUG
*******************************************************/

	/**
	 * Debug helper method.
	 * Can be called from /shoppingcart/debug/
	 * @return String
	 */
	public function debug() {
		$this->calculateOrderAttributes(true);
		return EcommerceTaskDebugCart::debug_object($this);
	}

}


