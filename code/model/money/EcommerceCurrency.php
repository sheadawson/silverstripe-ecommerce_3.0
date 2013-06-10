<?php

/**
 * Object to manage currencies
 *
 * @authors: Nicolaas [at] Sunny Side Up .co.nz
 * @package: ecommerce
 * @sub-package: money
 * Precondition : There should always be at least one currency usable.
 **/
class EcommerceCurrency extends DataObject {

	/**
	 * standard SS variable
	 * @var Array
	 */
	public static $db = array(
		"Code" => "Varchar(3)",
		"Name" => "Varchar(100)",
		"InUse" => "Boolean"
	);

	/**
	 * standard SS variable
	 * @var Array
	 */
	public static $indexes = array(
		"Code" => true,
	);

	/**
	 * standard SS variable
	 * @var Array
	 */
	public static $casting = array(
		"IsDefault" => "Boolean",
		"IsDefaultNice" => "Varchar",
		"InUseNice" => "Varchar",
		"ExchangeRate" => "Double",
		"DefaultSymbol" => "Varchar",
		"ShortSymbol" => "Varchar",
		"LongSymbol" => "Varchar"
	);

	/**
	 * standard SS variable
	 * @var Array
	 */
	public static $searchable_fields = array(
		"Code" => "PartialMatchFilter",
		"Name" => "PartialMatchFilter"
	);

	/**
	 * standard SS variable
	 * @var Array
	 */
	public static $field_labels = array(
		"Code" => "Short Code (e.g. NZD)",
		"Name" => "Name (e.g. New Zealand Dollar)",
		"InUse" => "It is available for use?",
		"ExchangeRate" => "Exchange Rate",
		"ExchangeRateExplanation" => "Exchange Rate explanation",
		"IsDefaultNice" => "Is default currency for site",
		"DefaultSymbol" => "Default symbol",
		"ShortSymbol" => "Short symbol",
		"LongSymbol" => "Long symbol"
	);

	/**
	 * standard SS variable
	 * @var Array
	 */
	public static $summary_fields = array(
		"Code" => "Code",
		"Name" => "Name",
		"InUseNice" => "Available",
		"IsDefaultNice" => "Default Currency",
		"ExchangeRate" => "Exchange Rate"
	); //note no => for relational fields

	/**
	 * standard SS variable
	 * @var String
	 */
	public static $singular_name = "Currency";
		function i18n_singular_name() { return _t("EcommerceCurrency.CURRENCY", "Currency");}

	/**
	 * standard SS variable
	 * @var String
	 */
	public static $plural_name = "Currencies";
		function i18n_plural_name() { return _t("EcommerceCurrency.CURRENCIES", "Currencies");}

	/**
	 * standard SS variable
	 * @var String
	 */
	public static $default_sort = "\"InUse\" DESC, \"Name\" ASC, \"Code\" ASC";

	/**
	 * standard SS variable
	 * @var Array
	 */
	public static $defaults = array(
		"InUse" => true
	);

	/**
	 * Standard SS Method
	 * @param Member $member
	 * @var Boolean
	 */
	public function canCreate($member = null) {
		return $this->canEdit($member);
	}

	/**
	 * Standard SS Method
	 * @param Member $member
	 * @var Boolean
	 */
	public function canView($member = null) {
		return true;
	}

	/**
	 * Standard SS Method
	 * @param Member $member
	 * @var Boolean
	 */
	public function canEdit($member = null) {
		if(!$member) {
			$member = Member::currentUser();
		}
		if($member && $member->IsShopAdmin()) {
			return true;
		}
		return parent::canEdit($member);
	}

	/**
	 * Standard SS method
	 * @param Member $member
	 * @return Boolean
	 */
	function canDelete($member = null){
		return ! $this->InUse && self::get_list()->Count() > 1;
	}

	/**
	 * NOTE: when there is only one currency we return an empty DataList
	 * as one currency is meaningless.
	 * @return DataList | null
	 */
	public static function ecommerce_currency_list(){
		$dos = EcommerceCurrency::get()
			->Filter(array("InUse" => 1))
			->Sort(
				array(
					"IF(\"Code\" = '".EcommerceConfig::get("EcommerceCurrency", "default_currency")."', 0, 1)" => "ASC",
					"Name" => "ASC",
					"Code" => "ASC"
				)
			);
		if($dos->count() < 2) {
			return null;
		}
		return $dos;

	}

	public static function get_list() {
		return EcommerceCurrency::get()
			->filter(array("InUse" => 1))
			->sort(
				array(
					"IF(\"Code\" = '".EcommerceConfig::get("EcommerceCurrency", "default_currency")."', 0, 1)" => "ASC",
					"Name" =>  "ASC",
					"Code" => "ASC"
				)
			);
	}


	/**
	 * @param Float $price
	 * @param Order $order
	 * @return EcommerceMoney | Null
	 */
	public static function display_price($price, Order $order = null){
		return self::get_money_object_from_order_currency($price, $order);
	}

	/**
	 * @param Float $price
	 * @param Order
	 * @return
	 */
	public static function get_money_object_from_order_currency($price, Order $order = null) {
		if(! $order) {
			$order = ShoppingCart::current_order();
		}
		$currency = $order->CurrencyUsed();
		if($order) {
			if($order->HasAlternativeCurrency()) {
				$exchangeRate = $order->ExchangeRate;
				if($exchangeRate && $exchangeRate != 1) {
					$price = $exchangeRate * $price;
				}
			}
		}
		return DBField::create_field('Money', array('Amount' => $price, 'Currency' => $currency->Code));
	}

	/**
	 * returns the default currency
	 *
	 * @return NULL | EcommerceCurrency
	 */
	public static function default_currency(){
		return EcommerceCurrency::get()
			->Filter(
				array(
					"Code" => EcommerceConfig::get("EcommerceCurrency", "default_currency"),
					"InUse" => 1
				)
			)
			->First();
	}

	/**
	 *
	 * @return Int
	 */

	public static function default_currency_id() {
		$currency = self::default_currency();
		return $currency ? $currency->ID : 0;
	}

	/**
	 * Only returns a currency when it is a valid currency.
	 *
	 * @param String $currencyCode - the code of the currency
	 * @return EcommerceCurrency | Null
	 */
	public static function get_one_from_code($currencyCode) {
		return EcommerceCurrency::get()
			->Filter(
				array(
					"Code" => $currencyCode,
					"InUse" => 1
				)
			)
			->First();
	}

	/**
	 * STANDARD SILVERSTRIPE STUFF
	 **/
	function getCMSFields(){
		$fields = parent::getCMSFields();
		$fieldLabels = $this->fieldLabels();
		$fields->addFieldToTab("Root.Main", new ReadonlyField("IsDefaulNice", $fieldLabels["IsDefaultNice"], $this->getIsDefaultNice()));
		if(!$this->isDefault()) {
			$fields->addFieldToTab("Root.Main", new ReadonlyField("ExchangeRate", $fieldLabels["ExchangeRate"], $this->ExchangeRate()));
			$fields->addFieldToTab("Root.Main", new ReadonlyField("ExchangeRateExplanation", $fieldLabels["ExchangeRateExplanation"], $this->ExchangeRateExplanation()));
		}
		$fields->addFieldsToTab("Root.Main", array(
			new HeaderField("Symbols"),
			new ReadonlyField("DefaultSymbol", "Default"),
			new ReadonlyField("ShortSymbol", "Short"),
			new ReadonlyField("LongSymbol", "Long")
		));
		return $fields;
	}

	function DefaultSymbol() {return $this->getDefaultSymbol();}
	function getDefaultSymbol() {return EcommerceMoney::get_default_symbol($this->Code);}

	function ShortSymbol() {return $this->getShortSymbol();}
	function getShortSymbol() {return EcommerceMoney::get_short_symbol($this->Code);}

	function LongSymbol() {return $this->getLongSymbol();}
	function getLongSymbol() {return EcommerceMoney::get_long_symbol($this->Code);}

	/**
	 * casted variable method
	 * @return Boolean
	 */
	public function IsDefault() {return $this->getIsDefault();}
	public function getIsDefault() {
		$outcome = false;
		if($this->exists()) {
			if(!$this->Code) {
				user_error("This currency (ID = ".$this->ID.") does not have a code ");
			}
		}
		return strtolower($this->Code) ==  strtolower(EcommerceConfig::get("EcommerceCurrency", "default_currency"));
	}

	/**
	 * casted variable method
	 * @return String
	 */
	public function IsDefaultNice() {return $this->getIsDefaultNice();}
	public function getIsDefaultNice() {
		if($this->getIsDefault()) {
			return _t("EcommerceCurrency.YES", "Yes");
		}
		else {
			return _t("EcommerceCurrency.NO", "No");
		}
	}

	/**
	 * casted variable method
	 * @return String
	 */
	public function InUseNice() {return $this->getInUseNice();}
	public function getInUseNice(){
		if($this->InUse) {
			return _t("EcommerceCurrency.YES", "Yes");
		}
		else {
			return _t("EcommerceCurrency.NO", "No");
		}
	}

	/**
	 * casted variable
	 * @return Double
	 * @todo $className is not used at all here
	 */
	public function ExchangeRate() {return $this->getExchangeRate();}
	public function getExchangeRate() {
		$exchangeRateProviderClassName = EcommerceConfig::get('EcommerceCurrency', 'exchange_provider_class');
		$exchangeRateProvider = new $exchangeRateProviderClassName();
		return $exchangeRateProvider->ExchangeRate(EcommerceConfig::get('EcommerceCurrency', 'default_currency'), $this->Code);
	}

	/**
	 * casted variable
	 * @return String
	 */
	public function ExchangeRateExplanation(){ return $this->getExchangeRateExplanation();}
	public function getExchangeRateExplanation(){
		$string = "1 ".EcommerceConfig::get("EcommerceCurrency", "default_currency")." = ".round($this->getExchangeRate(), 3)." ".$this->Code;
		$exchangeRate = $this->getExchangeRate();
		$exchangeRateError = "";
		if(!$exchangeRate) {
			$exchangeRate = 1;
			$exchangeRateError = _t("EcommerceCurrency.EXCHANGE_RATE_ERROR", "Error in exchange rate. ");
		}
		$string .= ", 1 ".$this->Code." = ".round(1 / $exchangeRate, 3)." ".EcommerceConfig::get("EcommerceCurrency", "default_currency").". ".$exchangeRateError;
	}

	/**
	 * @return Boolean
	 */
	public function IsCurrent() {
		$order = ShoppingCart::current_order();
		return $order ? $order->CurrencyUsedID == $this->ID : false;
	}

	/**
	 * Returns the link that can be used in the shopping cart to
	 * set the preferred currency to this one.
	 * For example: /shoppingcart/setcurrency/nzd/
	 * @return String
	 */
	public function Link() {
		return ShoppingCart_Controller::set_currency_link($this->Code);
	}

	/**
	 * returns the link type
	 * @return String (link | default | current)
	 */
	public function LinkingMode() {
		$linkingMode = '';
		if($this->IsDefault()) {
			$linkingMode .= ' default';
		}
		if($this->IsCurrent()) {
			$linkingMode .= ' current';
		}
		else {
			$linkingMode .= ' link';
		}
		return $linkingMode;
	}

	protected function validate() {
		$result = parent::validate();
		//TO DO - FIX!!!!
		return $result;
		$errors = array();
		if(! $this->Code || mb_strlen($this->Code) != 3) {
			$errors[] = 'The code must be 3 characters long.';
		}
		if(! $this->Name) {
			$errors[] = 'The name is required.';
		}
		if(! count($errors)) {
			$this->Code = strtoupper($this->Code);
			// Check that there are no 2 same code currencies in use
			if($this->isChanged('Code')) {
				$currencies = EcommerceCurrency::get()->where("UPPER(\"Code\") = '$this->Code' AND \"InUse\" = 1");
				if($currencies && $currencies->count()) {
					$errors[] = "There is alreay another currency in use which code is '$this->Code'.";
				}
			}
		}
		foreach($errors as $error) {
			$result->error($error);
		}
		return $result;
	}

	/**
	 * Standard SS Method
	 * Adds the default currency
	 */
	public function populateDefaults() {
		parent::populateDefaults();
		$this->InUse = true;
	}

	function onBeforeWrite() {
		parent::onBeforeWrite();
		// Check that there is always at least one currency in use
		if(! $this->InUse) {
			$list = self::get_list();
			if($list->count() == 0 || ($list->Count() == 1 && $list->First()->ID == $this->ID)) {
				$this->InUse = true;
			}
		}
	}


	/**
	 * Standard SS Method
	 * Adds the default currency
	 */
	function requireDefaultRecords() {
		parent::requireDefaultRecords();

		$currency = self::default_currency();
		if(! $currency) {
			self::create_new(EcommerceConfig::get('EcommerceCurrency', 'default_currency'));
		}
	}

	static function create_new($code) {
		$code = strtolower($code);
		$name = $code;
		if(isset(self::$currencies[$code])) {
			$name = self::$currencies[$code];
		}
		$name = ucwords($name);
		$currency = new EcommerceCurrency(array(
			'Code' => $code,
			'Name' => $name,
			'InUse' => true
		));
		$valid = $currency->write();
		if($valid) {
			return $currency;
		}
	}

	/**
	 * Debug helper method.
	 * Can be called from /shoppingcart/debug/
	 * @return String
	 */
	public function debug() {
		return EcommerceTaskDebugCart::debug_object($this);
	}

	static $currencies = array(
		'afa' => 'afghanistan afghanis',
		'all' => 'albania leke',
		'dzd' => 'algeria dinars',
		'ars' => 'argentina pesos',
		'aud' => 'australia dollars',
		'ats' => 'austria schillings*',
		'bsd' => 'bahamas dollars',
		'bhd' => 'bahrain dinars',
		'bdt' => 'bangladesh taka',
		'bbd' => 'barbados dollars',
		'bef' => 'belgium francs*',
		'bmd' => 'bermuda dollars',
		'brl' => 'brazil reais',
		'bgn' => 'bulgaria leva',
		'cad' => 'canada dollars',
		'xof' => 'cfa bceao francs',
		'xaf' => 'cfa beac francs',
		'clp' => 'chile pesos',
		'cny' => 'china yuan renminbi',
		'cop' => 'colombia pesos',
		'crc' => 'costa rica colones',
		'hrk' => 'croatia kuna',
		'cyp' => 'cyprus pounds',
		'czk' => 'czech republic koruny',
		'dkk' => 'denmark kroner',
		'dem' => 'deutsche (germany) marks*',
		'dop' => 'dominican republic pesos',
		'nlg' => 'dutch (netherlands) guilders*',
		'xcd' => 'eastern caribbean dollars',
		'egp' => 'egypt pounds',
		'eek' => 'estonia krooni',
		'eur' => 'euro',
		'fjd' => 'fiji dollars',
		'fim' => 'finland markkaa*',
		'frf' => 'france francs*',
		'dem' => 'germany deutsche marks*',
		'xau' => 'gold ounces',
		'grd' => 'greece drachmae*',
		'nlg' => 'holland (netherlands) guilders*',
		'hkd' => 'hong kong dollars',
		'huf' => 'hungary forint',
		'isk' => 'iceland kronur',
		'xdr' => 'imf special drawing right',
		'inr' => 'india rupees',
		'idr' => 'indonesia rupiahs',
		'irr' => 'iran rials',
		'iqd' => 'iraq dinars',
		'iep' => 'ireland pounds*',
		'ils' => 'israel new shekels',
		'itl' => 'italy lire*',
		'jmd' => 'jamaica dollars',
		'jpy' => 'japan yen',
		'jod' => 'jordan dinars',
		'kes' => 'kenya shillings',
		'krw' => 'korea (south) won',
		'kwd' => 'kuwait dinars',
		'lbp' => 'lebanon pounds',
		'luf' => 'luxembourg francs*',
		'myr' => 'malaysia ringgits',
		'mtl' => 'malta liri',
		'mur' => 'mauritius rupees',
		'mxn' => 'mexico pesos',
		'mad' => 'morocco dirhams',
		'nlg' => 'netherlands guilders*',
		'nzd' => 'new zealand dollars',
		'nok' => 'norway kroner',
		'omr' => 'oman rials',
		'pkr' => 'pakistan rupees',
		'xpd' => 'palladium ounces',
		'pen' => 'peru nuevos soles',
		'php' => 'philippines pesos',
		'xpt' => 'platinum ounces',
		'pln' => 'poland zlotych',
		'pte' => 'portugal escudos*',
		'qar' => 'qatar riyals',
		'rol' => 'romania lei',
		'rub' => 'russia rubles',
		'sar' => 'saudi arabia riyals',
		'xag' => 'silver ounces',
		'sgd' => 'singapore dollars',
		'skk' => 'slovakia koruny',
		'sit' => 'slovenia tolars',
		'zar' => 'south africa rand',
		'krw' => 'south korea won',
		'esp' => 'spain pesetas*',
		'xdr' => 'special drawing rights (imf)',
		'lkr' => 'sri lanka rupees',
		'sdd' => 'sudan dinars',
		'sek' => 'sweden kronor',
		'chf' => 'switzerland francs',
		'twd' => 'taiwan new dollars',
		'thb' => 'thailand baht',
		'ttd' => 'trinidad and tobago dollars',
		'tnd' => 'tunisia dinars',
		'try' => 'turkey new lira',
		'trl' => 'turkey lira*',
		'aed' => 'united arab emirates dirhams',
		'gbp' => 'united kingdom pounds',
		'usd' => 'united states dollars',
		'veb' => 'venezuela bolivares',
		'vnd' => 'vietnam dong',
		'zmk' => 'zambia kwacha'
	);
}
