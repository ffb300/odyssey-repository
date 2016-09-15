<?php
/**
 * @package Odyssey
 * @copyright Copyright (c) 2016 Lucas Sanner
 * @license GNU General Public License version 3, or later
 */


defined('_JEXEC') or die;
require_once JPATH_ROOT.'/administrator/components/com_odyssey/helpers/utility.php';


class PriceruleHelper
{
  public static function getCatalogPriceRules($travel, $travelId, $catId)
  {
    $user = JFactory::getUser();
    //Get user group ids to which the user belongs to.
    $groups = JAccess::getGroupsByUser($user->get('id'));
    //Get current date and time (equal to NOW() in SQL).
    $now = JFactory::getDate('now', JFactory::getConfig()->get('offset'))->toSql(true);
    $catalogPrules = array();

    //Collect all the departure ids of the travel.
    $dptIds = array();
    foreach($travel as $departure) {
      $dptIds[] = $departure['dpt_id'];
    }

    $db = JFactory::getDbo();
    $query = $db->getQuery(true);
    //Get the price rules linked to the travel.
    //Note: Get a travel price rule row for each departure of the step sequence multiplied with the number of passengers.
    $query->select('pr.name, pr.operation, pr.behavior, pr.show_rule, pr.recipient, pr.ordering,'.
	           'tpr.prule_id, tpr.dpt_id, tpr.psgr_nb, tpr.value')
	  ->from('#__odyssey_pricerule AS pr')
	  ->join('INNER', '#__odyssey_travel_pricerule AS tpr ON tpr.prule_id=pr.id')
	  ->join('INNER', '#__odyssey_prule_recipient AS prr ON (pr.recipient="customer" AND prr.item_id='.(int)$user->get('id').')'.
	                  ' OR (pr.recipient="customer_group" AND prr.item_id IN ('.implode(',', $groups).'))')
	  ->where('pr.prule_type="catalog" AND pr.target="travel" AND tpr.travel_id='.(int)$travelId)
	  ->where('tpr.dpt_id IN('.implode(',', $dptIds).') AND prr.prule_id=pr.id AND pr.published=1')
	  //Check against publication dates (start and stop).
	  ->where('('.$db->quote($now).' < pr.publish_down OR pr.publish_down = "0000-00-00 00:00:00")')
	  ->where('('.$db->quote($now).' > pr.publish_up OR pr.publish_up = "0000-00-00 00:00:00")')
	  ->order('pr.ordering, tpr.prule_id, tpr.dpt_id, tpr.psgr_nb');
    $db->setQuery($query);
    $travelPrules = $db->loadAssocList();

    if(!empty($travelPrules)) {
      //Rearrange price rule data. 
      $catalogPrules = PriceruleHelper::setTravelPruleRows($travelPrules);
    }

    $query->clear();
    //Get the price rules linked to the travel category.
    $query->select('pr.name, pr.operation, pr.value, pr.behavior, pr.show_rule, pr.recipient, pr.ordering, prt.prule_id, prt.psgr_nbs')
	  ->from('#__odyssey_pricerule AS pr')
	  ->join('INNER', '#__odyssey_prule_target AS prt ON prt.prule_id=pr.id')
	  ->join('INNER', '#__odyssey_prule_recipient AS prr ON (pr.recipient="customer" AND prr.item_id='.(int)$user->get('id').')'.
	                  ' OR (pr.recipient="customer_group" AND prr.item_id IN ('.implode(',', $groups).'))')
	  ->where('pr.prule_type="catalog" AND pr.target="travel_cat" AND prt.item_id='.(int)$catId.' AND prr.prule_id=pr.id AND pr.published=1')
	  //Check against publication dates (start and stop).
	  ->where('('.$db->quote($now).' < pr.publish_down OR pr.publish_down = "0000-00-00 00:00:00")')
	  ->where('('.$db->quote($now).' > pr.publish_up OR pr.publish_up = "0000-00-00 00:00:00")')
	  ->order('pr.ordering');
//file_put_contents('debog_prule.txt', print_r($query->__toString(), true));
    $db->setQuery($query);
    $travelCatPrules = $db->loadAssocList();

    if(!empty($travelCatPrules)) {
      //Some travel price rules have previously been found.
      if(!empty($catalogPrules)) {
	//Rearrange price rule data. 
	$travelCatPrules = PriceruleHelper::setTravelCatPruleRows($travelCatPrules, $travel, $catId);
	//Merge travel and travel category price rules together.
	$catalogPrules = array_merge($catalogPrules, $travelCatPrules);

	//The elements of the merged array must be reorder according to their "ordering" attribute.
	//In order to do so we use a simple bubble sort algorithm.
	$nbPrules = count($catalogPrules);
	for($i = 0; $i < $nbPrules; $i++) {
	  for($j = 0; $j < $nbPrules - 1; $j++) {
	    if($catalogPrules[$j]['ordering'] > $catalogPrules[$j + 1]['ordering']) {
	      $temp = $catalogPrules[$j + 1];
	      $catalogPrules[$j + 1] = $catalogPrules[$j];
	      $catalogPrules[$j] = $temp;
	    }
	  }
	}
      }
      else {
	//Rearrange price rule data. 
	$catalogPrules = PriceruleHelper::setTravelCatPruleRows($travelCatPrules, $travel, $catId);
      }
    }

    //No price rules have been found.
    if(empty($catalogPrules)) {
      return $catalogPrules;
    }

    //Grab the user session.
    $session = JFactory::getSession();
    //Get the coupon array to check possible exclusive coupon price rules. 
    $coupons = $session->get('coupons', array(), 'odyssey'); 

    //Check for a possible exclusive price rule. 
    $delete = false;
    foreach($catalogPrules as $key => $catalogPrule) {
      if($delete) {
	unset($catalogPrules[$key]);
	continue;
      }

      //The price rule is exclusive.
      if($catalogPrule['behavior'] == 'XOR') {
	//Price rules coming next must be deleted.
	$delete = true;
      }

      //The exclusive coupon price rules must be checked first.
      if($catalogPrule['behavior'] == 'CPN_XOR') {
	//Allow deleting only if the coupon has been validated by the customer.
	if(in_array($catalogPrule['prule_id'], $coupons)) {
	  $delete = true;
	}
      }
    }

    return $catalogPrules;
  }


  //Convert all rows of the same price rule into a single row containing nested arrays for
  //departure ids and value per passengers.
  public static function setTravelPruleRows($pruleRows)
  {
    $pruleIds = $prules = array();
    $currentDptId = 0;

    foreach($pruleRows as $key => $pruleRow) {
      //We're dealing with a new price rule.
      if(!in_array($pruleRow['prule_id'], $pruleIds)) {
	//Store the id of the new price rule.
	$pruleIds[] = $pruleRow['prule_id'];
	//Create a dpt_ids attribute in which we store an array of dpt_id containing the
	//value for each passenger in an array. 
	$pruleRow['dpt_ids'] = array($pruleRow['dpt_id'] => array($pruleRow['psgr_nb'] => UtilityHelper::formatNumber($pruleRow['value'])));
	//Set the current dpt_id.
	$currentDptId = $pruleRow['dpt_id'];
	//Remove unwanted variables.
	unset($pruleRow['dpt_id']);
	unset($pruleRow['psgr_nb']);
	unset($pruleRow['value']);
	//Add the new price rule.
	$prules[] = $pruleRow;
      }
      else { //We're dealing with an existing price rule.
	$currentId = count($prules) - 1;
	//It's the same dpt_id.
	if($pruleRow['dpt_id'] == $currentDptId) {
	  //Just add the passenger value in the dpt_id array.
	  $prules[$currentId]['dpt_ids'][$pruleRow['dpt_id']][$pruleRow['psgr_nb']] = UtilityHelper::formatNumber($pruleRow['value']); 
	}
	else { //We're dealing with a new dpt_id.
	  //Add a new dpt_id as well as an array containing the first passenger value.
	  $prules[$currentId]['dpt_ids'][$pruleRow['dpt_id']] = array($pruleRow['psgr_nb'] => UtilityHelper::formatNumber($pruleRow['value']));
	  //Set the current dpt_id.
	  $currentDptId = $pruleRow['dpt_id'];
	}
      }
    }

    return $prules;
  }


  //Add departure ids and value per passengers as nested arrays into each row.
  public static function setTravelCatPruleRows($pruleRows, $travel, $catId)
  {
    foreach($pruleRows as $key => $pruleRow) {
      //Turn the string value into an array.
      $psgrNbs = explode(',', $pruleRow['psgr_nbs']);
      //Add a dpt_ids attribute.
      $pruleRows[$key]['dpt_ids'] = array();

      //Set departure ids and value per passengers according to the travel data.
      foreach($travel as $data) {
	//Add a new dpt_id.
	$pruleRows[$key]['dpt_ids'][$data['dpt_id']] = array();
	//Set a price rule value for each passenger.
	foreach($data['price_per_psgr'] as $psgrNb => $price) {
	  //Apply the price rule value if the passenger number matches.
	  //Note: Zero means all passenger numbers.
	  if($pruleRow['psgr_nbs'] == 0 || in_array($psgrNb, $psgrNbs)) {
	    $pruleRows[$key]['dpt_ids'][$data['dpt_id']][$psgrNb] = $pruleRow['value'];
	  }
	  else { //Passenger number doesn't match.
	    $pruleRows[$key]['dpt_ids'][$data['dpt_id']][$psgrNb] = '0.00';
	  }
	  //Remove unwanted variables.
	  unset($pruleRows[$key]['value']);
	  unset($pruleRows[$key]['psgr_nbs']);
	}
      }
    }

    return $pruleRows;
  }


  //Get the price rules matching the travel selected by the customer.
  //Note: Price rules (if any) are already known but they have to be computed once again
  //in order to be stored into the user's session.
  public static function getMatchingTravelPriceRules($pruleIds, $travel)
  {
    $db = JFactory::getDbo();
    $query = $db->getQuery(true);
    //Get both travel and travel_cat target types at once.
    $query->select('pr.id, pr.name, pr.prule_type, pr.operation, pr.behavior, pr.target,'.
	           'pr.value, pr.show_rule, pr.ordering, tpr.value AS tpr_value')
	  ->from('#__odyssey_pricerule AS pr')
	  ->join('LEFT', '#__odyssey_prule_target AS t ON t.prule_id=pr.id AND t.item_id='.(int)$travel['catid'])
	  ->join('LEFT', '#__odyssey_travel_pricerule AS tpr ON tpr.prule_id=pr.id AND tpr.travel_id='.(int)$travel['travel_id'].
	                 ' AND tpr.dpt_step_id='.(int)$travel['dpt_step_id'].' AND tpr.dpt_id='.(int)$travel['dpt_id'].
			 ' AND tpr.psgr_nb='.(int)$travel['nb_psgr'])
	  ->where('pr.id IN('.implode(',', $pruleIds).')')
	  ->order('pr.ordering');
    $db->setQuery($query);
    $travelPrules = $db->loadAssocList();

    //Get the normal price.
    $price = $travel['travel_price'];
    $priceRules = array();
    foreach($travelPrules as $travelPrule) {
      //Set the proper price rule value according to the target type of the price rule.
      $pruleValue = $travelPrule['value'];
      if($travelPrule['target'] == 'travel') {
	$pruleValue = UtilityHelper::formatNumber($travelPrule['tpr_value']);
      }

      //Set the needed attributes then store the price rule.
      $priceRule = array('id' => $travelPrule['id'], 
	                 'name' => $travelPrule['name'],
	                 'prule_type' => $travelPrule['prule_type'],
	                 'behavior' => $travelPrule['behavior'],
	                 'operation' => $travelPrule['operation'],
	                 'target' => $travelPrule['target'],
	                 'show_rule' => $travelPrule['show_rule'],
	                 'value' => $pruleValue,
	                 'ordering' => $travelPrule['ordering']);
      $priceRules[] = $priceRule;

      //Apply the price rule value. 
      $price = PriceruleHelper::computePriceRule($travelPrule['operation'], $pruleValue, $price);
    }

    //Add the price rule data to the travel.
    $travel['pricerules'] = $priceRules;
    $travel['normal_price'] = $travel['travel_price'];
    $travel['travel_price'] = UtilityHelper::formatNumber($price, 5);

    return $travel;
  }


  public static function computePriceRule($operation, $pruleValue, $price)
  {
    $operation = PriceruleHelper::getOperationAttributes($operation);

    if($operation->type == 'percent') {
      $pruleValue = $price * ($pruleValue / 100);
    }

    if($operation->operator == '+') {
      return $price + $pruleValue;
    }
    else { //minus
      return $price - $pruleValue;
    }

    return $price;
  }


  protected static function getOperationAttributes($operation)
  {
    $op = new JObject;

    //Set the type attribute.
    if(preg_match('#%#', $operation)) {
      $op->type = 'percent';
    }
    else {
      $op->type = 'absolute';
    }

    //Extract the operator from the operation sign.
    if(preg_match('#([+|-])%#', $operation, $matches)) {
      $op->operator = $matches[1];
    }
    else {
      $op->operator = $operation;
    }

    return $op;
  }


  public static function checkCoupon($code)
  {
    //Check for a valid code.
    if(!preg_match('#^[a-zA-Z0-9-_]{5,}$#', $code)) {
      JFactory::getApplication()->enqueueMessage(JText::_('COM_ODYSSEY_ERROR_COUPON_CODE_NOT_VALID'), 'warning');
      return false;
    }

    $user = JFactory::getUser();

    $db = JFactory::getDbo();
    $query = $db->getQuery(true);
    //Get the needed coupon data to validate (or not) the code.
    $query->select('c.id, c.name, c.prule_id, c.max_nb_uses, c.max_nb_coupons, c.login_mandatory, cc.nb_uses')
	  ->from('#__odyssey_coupon AS c')
	  ->join('LEFT', '#__odyssey_coupon_customer AS cc ON cc.customer_id='.(int)$user->get('id').' AND cc.code='.$db->quote($code))
	  ->where('c.code='.$db->quote($code).' AND c.published=1');
    // Setup the query
    $db->setQuery($query);
    $result = $db->loadAssoc();

    if(is_null($result)) {
      JFactory::getApplication()->enqueueMessage(JText::_('COM_ODYSSEY_WARNING_NO_MATCHING_CODE'), 'warning');
      return false;
    }

    //The customer must logged in before sending the coupon code.
    if($result['login_mandatory'] == 1 && $user->get('guest') == 1) {
      // Redirect to login page.
      JFactory::getApplication()->enqueueMessage(JText::_('COM_ODYSSEY_MESSAGE_LOGIN_MANDATORY'), 'message');
      JFactory::getApplication()->redirect(JRoute::_('index.php?option=com_users&view=login', false));
      return;
    }

    //The stock of coupons is empty.
    if($result['max_nb_coupons'] == 0) {
      JFactory::getApplication()->enqueueMessage(JText::_('COM_ODYSSEY_NOTICE_NO_MORE_COUPON_AVAILABLE'), 'notice');
      return false;
    }

    //The number of uses per customer must be checked.
    if($result['max_nb_uses'] > 0) {
      //The number of uses has been reached (or exceeded) by the customer.
      if($result['nb_uses'] >= $result['max_nb_uses']) {
	JFactory::getApplication()->enqueueMessage(JText::_('COM_ODYSSEY_WARNING_COUPON_CANNOT_BE_USED'), 'warning');
	return false;
      }
    }

    //Grab the user session.
    $session = JFactory::getSession();
    //Create the coupon session array if it doesn't exist.
    if(!$session->has('coupons', 'odyssey')) {
      $session->set('coupons', array(), 'odyssey');
    }

    //Get the coupon session array.
    $coupons = $session->get('coupons', array(), 'odyssey');
    //If the price rule id is already in the array we leave the function to prevent to
    //decrease the stock of coupon (or increase the number of uses) once again.
    if(in_array($result['prule_id'], $coupons)) {
      JFactory::getApplication()->enqueueMessage(JText::_('COM_ODYSSEY_WARNING_COUPON_ALREADY_USED'), 'warning');
      return false;
    }

    //Store the price rule id.
    $coupons[] = $result['prule_id'];
    $session->set('coupons', $coupons, 'odyssey');

    if($user->get('guest') != 1 && $result['max_nb_uses'] > 0 && ($result['nb_uses'] < $result['max_nb_uses'] || empty($result['nb_uses']))) {
      if(empty($result['nb_uses'])) {
	$columns = array('customer_id', 'code', 'nb_uses');
	$values = (int)$user->get('id').','.$db->quote($code).',1'; 
	//Insert a new row for this customer/code.
	$query->clear();
	$query->insert('#__odyssey_coupon_customer')
	      ->columns($columns)
	      ->values($values);
	$db->setQuery($query);
	$db->execute();
      }
      else { //Increase the number of uses of the coupon for this customer.
	$query->clear();
	$query->update('#__odyssey_coupon_customer')
	      ->set('nb_uses = nb_uses + 1')
	      ->where('customer_id='.(int)$user->get('id').' AND code='.$db->quote($code));
	$db->setQuery($query);
	$db->execute();
      }
    }

    //The stock of coupons is not unlimited (-1) so we have to decrease its value.
    if($result['max_nb_coupons'] > 0) {
      $query->clear();
      $query->update('#__odyssey_coupon')
	    ->set('max_nb_coupons = max_nb_coupons - 1')
	    ->where('id='.(int)$result['id']);
      $db->setQuery($query);
      $db->execute();
    }

    return;
  }
}

