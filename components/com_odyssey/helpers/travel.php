<?php
/**
 * @package Odyssey
 * @copyright Copyright (c) 2016 Lucas Sanner
 * @license GNU General Public License version 3, or later
 */

defined('_JEXEC') or die;
require_once JPATH_ROOT.'/administrator/components/com_odyssey/helpers/utility.php';


class TravelHelper
{
  public static function getPricesStartingAt($travelIds)
  {
    $db = JFactory::getDbo();
    $query = $db->getQuery(true);
    $nowDate = $db->quote(JFactory::getDate('now', JFactory::getConfig()->get('offset'))->toSql(true));
    $pricesStartingAt = array();

    //Get the lower price for one passenger among all the scheduled departures of the travel. 
    $query->select('t.id, MIN(tp.price) AS price_starting_at')
	  ->from('#__odyssey_travel_price AS tp')
	  ->join('INNER', '#__odyssey_travel AS t ON t.id=tp.travel_id')
	  ->join('INNER', '#__odyssey_departure_step_map AS ds ON ds.step_id=t.dpt_step_id AND (ds.date_time >= '.$nowDate.
		          ' OR ds.date_time_2 >= '.$nowDate.')')
	  ->where('t.id IN('.implode(',', $travelIds).') AND tp.dpt_id=ds.dpt_id AND tp.psgr_nb=1')
	  ->group('t.id');
    $db->setQuery($query);
    $results = $db->loadAssocList();

    //Create a mapping array id/price for more convenience.
    foreach($results as $result) {
      $pricesStartingAt[$result['id']] = $result['price_starting_at'];
    }

    return $pricesStartingAt;
  }


  public static function getPeriodDates($fromDate, $toDate)
  {
    $nowDate = JFactory::getDate('now', JFactory::getConfig()->get('offset'))->toSql(true);

    //Remove time value as it is not used with period date type. 
    preg_match('#^([0-9]{4}-[0-9]{2}-[0-9]{2}) [0-9]{2}:[0-9]{2}:[0-9]{2}$#', $nowDate, $matches);
    $nowDate = $matches[1];

    preg_match('#^([0-9]{4}-[0-9]{2}-[0-9]{2}) [0-9]{2}:[0-9]{2}:[0-9]{2}$#', $fromDate, $matches);
    $fromDate = $matches[1];

    preg_match('#^([0-9]{4}-[0-9]{2}-[0-9]{2}) [0-9]{2}:[0-9]{2}:[0-9]{2}$#', $toDate, $matches);
    $toDate = $matches[1];

    //If the starting date is older than the current date we use the current date.
    if($nowDate > $fromDate) {
      $fromDate = $nowDate;
    }

    //Get the number of days between the starting date and the ending date.
    $date1 = date_create($fromDate);
    $date2 = date_create($toDate);
    $interval = date_diff($date1, $date2);
    $days = $interval->format('%a days');

    //Get the dates contained between the starting date and the ending date.
    $dates = array();
    //Note: starting date and ending date are included as departure (hence zero offset and +1).
    for($i = 0; $i < $days + 1; $i++) {
      //Reinitialize starting date whenever loop is running.
      $date1 = date_create($fromDate);
      //Compute the date value against the given number of days.
      date_add($date1, date_interval_create_from_date_string($i.' days'));
      $dates[] = date_format($date1, 'Y-m-d');
    }

    return $dates;
  }


  //Return the global settings of the application.
  public static function getSettings()
  {
    $parameters = JComponentHelper::getParams('com_odyssey');

    $db = JFactory::getDbo();
    $query = $db->getQuery(true);
    //
    $query->select('numerical, alpha AS currency_code, symbol, exchange_rate')
          ->from('#__odyssey_currency')
	  ->where('alpha='.$db->quote($parameters->get('currency_code')));
    $db->setQuery($query);
    $settings = $db->loadAssoc();

    //Set some required informations.
    $settings['rounding_rule'] = $parameters->get('rounding_rule');
    $settings['digits_precision'] = $parameters->get('digits_precision');
    $settings['option_time_limit'] = $parameters->get('option_time_limit');
    $settings['option_validity_period'] = $parameters->get('option_validity_period');
    $settings['option_reminder'] = $parameters->get('option_reminder');
    $settings['deposit_time_limit'] = $parameters->get('deposit_time_limit');
    $settings['deposit_reminder'] = $parameters->get('deposit_reminder');
    $settings['finalize_time_limit'] = $parameters->get('finalize_time_limit');
    $settings['deposit_rate'] = $parameters->get('deposit_rate');
    $settings['run_at_command'] = $parameters->get('run_at_command');

    //Set the proper currency display.
    $settings['currency'] = $parameters->get('currency_code');
    if($parameters->get('currency_display') == 'symbol') {
      $settings['currency'] = $settings['symbol'];
    }

    return $settings;
  }


  public static function getPaymentModes()
  {
    $db = JFactory::getDbo();
    $query = $db->getQuery(true);
    //Get the payment modes set into the backend component.
    $query->select('pm.id,pm.name,pm.description,pm.plugin_element')
          ->from('#__odyssey_payment_mode AS pm')
	  ->where('pm.published=1')
	  ->order('pm.ordering');
    $db->setQuery($query);
    $modes = $db->loadObjectList();

    //Get all the enabled odysseypayment plugins.
    $query->clear();
    $query->select('element')
          ->from('#__extensions')
	  ->where('type="plugin" AND folder="odysseypayment" AND enabled=1');
    $db->setQuery($query);
    $paymentPlugins = $db->loadColumn();

    //Store each found mode as an object into an array.
    $paymentModes = array();
    foreach($modes as $mode) {
      //First we check that the payment plugin which is assigned to the mode
      //item is installed and enabled.
      if(in_array($mode->plugin_element, $paymentPlugins)) {
	//The offline plugin can have several payment modes, so we need to 
	//slighly modified the plugin_element attribute of the object. 
	if($mode->plugin_element == 'offline') {
	  //The offline payment plugin is going to need an id for each offline
	  //payment mode found. So we pass the id at the end of the
	  //plugin_element attribute separated by an underscore. 
	  $mode->plugin_element = 'offline_'.$mode->id;
	  //Add the offline payment mode to the array.
	  $paymentModes[] = $mode;
	}
	else { //For "standard" plugins we just add the object as it is to the array.
	  $paymentModes[] = $mode;
	}
      }
    }

    return $paymentModes;
  }


  public static function initializeSession()
  {
    //Grab the user session.
    $session = JFactory::getSession();

    //Create the all of the required arrays to save the client's booking properly.

    $session->set('travel', array(), 'odyssey');
    $session->set('addons', array(), 'odyssey');
    $session->set('passengers', array(), 'odyssey');
    //Get all the global data needed during booking.
    $settings = TravelHelper::getSettings();
    $session->set('settings', $settings, 'odyssey');

    //Check for coupons array, create it if it doesn't exist.
    if(!$session->has('coupons', 'odyssey')) {
      $session->set('coupons', array(), 'odyssey');
    }

    //Safety variables.

    $session->set('end_booking', 0, 'odyssey');
    $session->set('submit', 0, 'odyssey');
    $session->set('location', '', 'odyssey');
    //Variable used to lock the order once it has been validated. 
    //It's also used to avoid the user to order again (after the order   
    //has been validated) by using the backspace key.   
    $session->set('locked', 0, 'odyssey');

    return;
  }


  //Delete all of the session data which has been used during the booking.
  public static function clearSession()
  {
    //Store the name of all the variables which should be deleted.
    $variables = array('travel','addons','settings','utility',
	               'passengers','locked','end_booking', 'coupons',
		       'location','order_id','submit');

    $session = JFactory::getSession();
    foreach($variables as $variable) {
      //Check if variable exists. If it does we delete it.
      if($session->has($variable, 'odyssey')) {
	$session->clear($variable, 'odyssey');
      }
    }

    return;
  }


  public static function checkBookingProcess()
  {
    //Grab the user session.
    $session = JFactory::getSession();

    //Check first that the travel array does exist which means that all the others needed
    //session variables exist as well. 
    if(!$session->has('travel', 'odyssey')) {
      $app = JFactory::getApplication();
      $app->redirect(JRoute::_('index.php?option=com_users&view=login'));
      return true;
    }

    $locked = $session->get('locked', 0, 'odyssey');

    //Booking and order are already set.
    if($locked) {
      $travel = $session->get('travel', array(), 'odyssey');
      $app = JFactory::getApplication();
      if($travel['booking_option'] == 'take_option') {
	//Redirect to the end of the process.
	$app->redirect('index.php?option=com_odyssey&task=end.confirmOption');
      }
      else {
	//Redirect to the payment part.
	$app->redirect(JRoute::_('index.php?option=com_odyssey&view=payment', false));
      }

      return true;
    }
  }


  //Build Javascript utility functions:
  public static function javascriptUtilities()
  {
    $db = JFactory::getDbo(); //For the Quote function.

    $js  = 'function hideButton(buttonId) {'."\n";
    $js .= '    var elements = document.getElementsByClassName(buttonId);'."\n";
    $js .= '    for(var i = 0; i < elements.length; i++) {'."\n";
    $js .= '      elements[i].style.visibility="hidden";'."\n";
    $js .= '    }'."\n";
    //$js .= '    document.getElementById(buttonId).style.visibility="hidden";'."\n";
    $js .= '    var messagePanel = getMessagePanel("waiting-message",'.$db->Quote(JText::_('COM_ODYSSEY_MESSAGE_WAITING_MESSAGE')).');'."\n";
    $js .= '    parentTag = document.getElementById(buttonId+"-message").parentNode;'."\n";
    $js .= '    parentTag.insertBefore(messagePanel, document.getElementById(buttonId+"-message"))'."\n";
    $js .= '    return;'."\n";
    $js .= '}'."\n\n";
    $js .= 'function getMessagePanel(panelId, message) {'."\n";
    $js .= '    var messagePanel = document.createElement("div");'."\n";
    $js .= '    messagePanel.setAttribute("id", panelId);'."\n";
    $js .= '    var text = document.createTextNode(message);'."\n";
    $js .= '    messagePanel.appendChild(text); //Insert the text whithin div tag.'."\n";
    $js .= '    return messagePanel;'."\n";
    $js .= '}'."\n";

    //Place the Javascript function into the html page header.
    $doc = JFactory::getDocument();
    $doc->addScriptDeclaration($js);

    return;
  }


  public static function checkInPassengers($form, $customerId = 0)
  {
    //Get the passenger ini file in which some settings are defined.
    $psgrIni = parse_ini_file(JPATH_ROOT.'/administrator/components/com_odyssey/models/forms/passenger.ini');
    $attributes = $psgrIni['attributes'];
    $types = $psgrIni['types'];
    $address = $psgrIni['address'];
    var_dump($psgrIni['is_address']);
    //var_dump($form);
    //If called from the frontend the user id is the customer id.
    if(!$customerId) {
      $customerId = JFactory::getUser()->get('id');
    }

    $passengers = $psgrNbs = array();
    foreach($form as $key => $value) {
      //Note: preloadpsgr refers to the preload passenger drop down lists and must not be taken in account.
      if(preg_match('#^([a-z_-]+)_([0-9]+)$#', $key, $matches) && preg_match('#^(?!preloadpsgr)#', $key)) {
	$name = $matches[1];
	$psgrNb = $matches[2];

	if(!in_array($psgrNb, $psgrNbs)) {
	  $psgrNbs[] = $psgrNb;

	  $passengers[]['data'] = array();
	  $idNb = count($passengers) - 1;
	  $passengers[$idNb]['address'] = array();
	  $passengers[$idNb]['address']['item_id'] = $form['id_'.$psgrNb];

	  $passengers[$idNb]['data']['customer_id'] = $customerId;

	  $passengers[$idNb]['data']['customer'] = 0;
	  if($psgrNb == 1) {
	    $passengers[$idNb]['data']['customer'] = 1;
	  }
	}
	else {
	  $idNb = count($passengers) - 1;
	}

	if((int)$psgrIni['is_address'] && in_array($name, $address)) {
	  $passengers[$idNb]['address'][$name] = $value;
	}
	else {
	  $passengers[$idNb]['data'][$name] = $value;
	}
      }
    }

    return $passengers;
  }


  /**
   * Compute the final amount of the travel.
   *
   * @param	array	$travel		data of the travel selected by the customer.
   *		array	$addons		data of the addons selected by the customer.
   *
   * @return	float	The final amount.
   */
  public static function getFinalAmount($travel, $addons)
  {
    $finalAmount = 0;
    foreach($addons as $addon) {
      $finalAmount += $addon['price'];

      foreach($addon['options'] as $option) {
	$finalAmount += $option['price'];
      }
    }

    $finalAmount += $travel['travel_price'];
    $finalAmount += $travel['transit_price'];

    return $finalAmount;
  }


  public static function getCustomerData($userId = 0)
  {
    if(!$userId) {
      $userId = JFactory::getUser()->get('id');
    }

    $db = JFactory::getDbo();
    $query = $db->getQuery(true);
    $query->select('u.name AS lastname, p.id, c.firstname, c.customer_title, a.street, a.city, a.postcode, a.phone,'.
	           'a.country_code, co.name AS country_name, co.lang_var AS country_lang_var, r.lang_var AS region_lang_var')
	  ->from('#__users AS u')
	  ->join('LEFT', '#__odyssey_customer AS c ON c.id=u.id')
	  ->join('LEFT', '#__odyssey_passenger AS p ON p.customer_id=u.id AND customer=1')
	  ->join('LEFT', '#__odyssey_address AS a ON a.item_id=u.id AND a.item_type="customer"')
	  ->join('LEFT', '#__odyssey_country AS co ON co.alpha_2=a.country_code')
	  ->join('LEFT', '#__odyssey_region AS r ON r.id_code=a.region_code')
	  ->where('u.id='.(int)$userId);
    $db->setQuery($query);
    $customerData = $db->loadAssoc();

    return $customerData;
  }


  //Send an appropriate email to customers according to the performed action.
  public static function sendEmail($emailType, $userId, $orderId = 0, $message = array())
  {
    //A reference to the global mail object (JMail) is fetched through the JFactory object. 
    //This is the object creating our mail.
    $mailer = JFactory::getMailer();

    $config = JFactory::getConfig();
    $sender = array($config->get('mailfrom'),
		    $config->get('fromname'));

    $mailer->setSender($sender);

    $user = JFactory::getUser($userId);
    $recipient = $user->email;

    $mailer->addRecipient($recipient);

    if(empty($message)) {
      //Get the proper email message according to the email type.
      $message = TravelHelper::getEmailMessage($emailType, $userId, $orderId);
    }
//file_put_contents('debog_send_email.txt', print_r($message, true)); 
    //Set the subject and body of the email.
    $body = $message['body'];
    $mailer->setSubject($message['subject']);
    //We want the body message in HTML.
    $mailer->isHTML(true);
    $mailer->Encoding = 'base64';
    $mailer->setBody($body);

    $send = $mailer->Send();

    //Check for error.
    if($send !== true) {
      JError::raiseWarning(500, JText::_('COM_ODYSSEY_CONFIRMATION_EMAIL_FAILED'));
      //Log the error.
      //ShopHelper::logEvent($this->codeLocation, 'sendmail_error', 0, 0, $send->get('message'));
      return false;
    }
    else {
      JFactory::getApplication()->enqueueMessage(JText::_('COM_ODYSSEY_CONFIRMATION_EMAIL_SUCCESS'));
    }

    return true;
  }


  //Build the email subject and body according to the email type.
  public static function getEmailMessage($emailType, $userId, $orderId = 0)
  {
    $bookingOptions = array('take_option', 'deposit', 'whole_price', 'remaining');

    //Send an email regarding a booking action of the customer.
    if(in_array($emailType, $bookingOptions)) {
      //Get data from the user session.
      $session = JFactory::getSession();
      $travel = $session->get('travel', array(), 'odyssey'); 
      $settings = $session->get('settings', array(), 'odyssey'); 
      $orderId = $travel['order_id'];
    }
    else {
      $settings = TravelHelper::getSettings();
    }

    //Get the needed data.
    $db = JFactory::getDbo();
    $query = $db->getQuery(true);
    $query->select('o.outstanding_balance, o.final_amount, o.nb_psgr, o.order_details, o.currency_code,'.
		   't.name AS travel_name, c.firstname, u.name AS lastname')
	  ->from('#__odyssey_order AS o')
	  ->join('LEFT', '#__odyssey_order_travel AS t ON t.order_id=o.id')
	  ->join('LEFT', '#__users AS u ON u.id='.(int)$userId)
	  ->join('LEFT', '#__odyssey_customer AS c ON c.id=u.id');

	  if(isset($travel['booking_option']) && $travel['booking_option'] != 'take_option') { //Something has been paid.
	    $query->select('tr.amount')
		  ->join('LEFT', '#__odyssey_order_transaction AS tr ON tr.order_id=o.id AND tr.amount_type='.$db->quote($travel['booking_option']));
	  }

    $query->where('o.id='.(int)$orderId);
    $db->setQuery($query);
    $result = $db->loadObject();

    //Initialise some variables.
    $websiteUrl = JURI::base();
    $currency = $settings['currency'];

    if(isset($travel['booking_option']) && $travel['booking_option'] != 'take_option') { 
      $amount = UtilityHelper::formatNumber($result->amount).' '.$currency;
      $outstandingBalance = UtilityHelper::formatNumber($result->outstanding_balance).' '.$currency;
    }
//file_put_contents('debog_file.txt', print_r($settings, true));
    $limitDate = UtilityHelper::getLimitDate($settings['option_validity_period']);
    $limitDate = JHTML::_('date', $limitDate, JText::_('DATE_FORMAT_LC2'));
    $finalAmount = UtilityHelper::formatNumber($result->final_amount).' '.$currency;

    //Get the corresponding subject and body.
    switch($emailType) {
      case 'take_option':
	$subject = JText::sprintf('COM_ODYSSEY_EMAIL_TAKE_OPTION_CONFIRMATION_SUBJECT', $result->travel_name);
	$body = JText::sprintf('COM_ODYSSEY_EMAIL_TAKE_OPTION_CONFIRMATION_BODY', $result->firstname, $result->lastname,
										  $result->travel_name, 
										  $limitDate,
										  $result->order_details,
										  $finalAmount, $websiteUrl);
	break;

      case 'deposit':
	$subject = JText::sprintf('COM_ODYSSEY_EMAIL_DEPOSIT_CONFIRMATION_SUBJECT', $result->travel_name);
	$body = JText::sprintf('COM_ODYSSEY_EMAIL_DEPOSIT_CONFIRMATION_BODY', $result->firstname, $result->lastname,
									      $amount, $result->travel_name, 
									      $outstandingBalance,
									      $result->order_details,
									      $finalAmount, $websiteUrl);
	break;

      case 'whole_price':
	$subject = JText::sprintf('COM_ODYSSEY_EMAIL_WHOLE_PRICE_CONFIRMATION_SUBJECT', $result->travel_name);
	$body = JText::sprintf('COM_ODYSSEY_EMAIL_WHOLE_PRICE_CONFIRMATION_BODY', $result->firstname, $result->lastname,
										  $amount, $result->travel_name, 
										  $result->order_details,
										  $finalAmount, $websiteUrl);
	break;

      case 'remaining':
	$subject = JText::sprintf('COM_ODYSSEY_EMAIL_REMAINING_CONFIRMATION_SUBJECT', $result->travel_name);
	$body = JText::sprintf('COM_ODYSSEY_EMAIL_REMAINING_CONFIRMATION_BODY', $result->firstname, $result->lastname,
										$amount, $result->travel_name, 
										$result->order_details,
										$finalAmount, $websiteUrl);
	break;
    }

    $body .= JText::_('COM_ODYSSEY_EMAIL_BODY_THANKS');
    $message = array('subject' => $subject, 'body' => $body);

    return $message;
  }
}
