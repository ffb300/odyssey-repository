<?php
/**
 * @package Odyssey
 * @copyright Copyright (c) 2016 - 2017 Lucas Sanner
 * @license GNU General Public License version 3, or later
 */


defined('_JEXEC') or die; //No direct access to this file.

jimport('joomla.application.component.modeladmin');
require_once JPATH_COMPONENT.'/helpers/utility.php';
require_once JPATH_COMPONENT.'/helpers/odyssey.php';


class OdysseyModelOrder extends JModelAdmin
{
  //Prefix used with the controller messages.
  protected $text_prefix = 'COM_ODYSSEY';

  //Returns a Table object, always creating it.
  //Table can be defined/overrided in the file: tables/mycomponent.php
  public function getTable($type = 'Order', $prefix = 'OdysseyTable', $config = array()) 
  {
    return JTable::getInstance($type, $prefix, $config);
  }


  public function getForm($data = array(), $loadData = true) 
  {
    $form = $this->loadForm('com_odyssey.order', 'order', array('control' => 'jform', 'load_data' => $loadData));

    if(empty($form)) {
      return false;
    }

    return $form;
  }


  protected function loadFormData() 
  {
    // Check the session for previously entered form data.
    $data = JFactory::getApplication()->getUserState('com_odyssey.edit.order.data', array());

    if(empty($data)) {
      $data = $this->getItem();
    }

    return $data;
  }


  //Overrided functions.
  public function getItem($pk = null)
  {
    $item = parent::getItem($pk);

    $db = $this->getDbo();
    $query = $db->getQuery(true);
    //Get and set the customer name plus some travel data.
    $query->select('u.name, c.firstname, t.name AS travel_name, t.date_type')
	  ->from('#__users AS u')
	  ->join('LEFT', '#__odyssey_customer AS c ON c.id=u.id')
	  ->join('LEFT', '#__odyssey_order_travel AS t ON t.order_id='.(int)$item->id)
	  ->where('u.id='.(int)$item->customer_id);
    $db->setQuery($query);
    $result = $db->loadAssoc();
    $item->customer = $result['name'].' '.$result['firstname'];
    $item->date_type = $result['date_type'];

    $digitsPrecision = JComponentHelper::getParams('com_odyssey')->get('digits_precision');
    $item->final_amount = UtilityHelper::formatNumber($item->final_amount, $digitsPrecision);
    $item->outstanding_balance = UtilityHelper::formatNumber($item->outstanding_balance, $digitsPrecision);
    $item->digits_precision = $digitsPrecision;

    return $item;
  }


  public function getTransactions($id)
  {
    $db = $this->getDbo();
    $query = $db->getQuery(true);
    $query->select('*')
	  ->from('#__odyssey_order_transaction')
	  ->where('order_id='.(int)$id)
	  ->order('created');
    $db->setQuery($query);

    return $db->loadObjectList();
  }


  public function getPassengers($id, $nbPsgr)
  {
    //Get the passenger ini file in which some settings are defined.
    $psgrIni = parse_ini_file(OdysseyHelper::getOverridedFile(JPATH_BASE.'/components/com_odyssey/models/forms/passenger.ini'));
    $attributes = $psgrIni['attributes'];
    $select = '';

    foreach($attributes as $attribute) {
      $select .= 'p.'.$attribute.',';
    }

    if($psgrIni['is_address']) {
      $address = $psgrIni['address'];
      foreach($address as $value) {
	$select .= 'a.'.$value.',';
      }
    }

    //Remove comma from the end of the string.
    //$select = substr($select, 0, -1);

    $db = $this->getDbo();
    $query = $db->getQuery(true);
    $query->select($select.'p.id')
	  ->from('#__odyssey_order_passenger AS op')
	  ->join('LEFT', '#__odyssey_passenger AS p ON p.id=op.psgr_id');

    if($psgrIni['is_address']) {
      $query->join('LEFT', '#__odyssey_address AS a ON a.item_id=op.psgr_id AND a.item_type="passenger"');
    }

    $query->where('op.order_id='.(int)$id)
	  ->order('p.customer DESC');
    $db->setQuery($query);
    $passengers = $db->loadAssocList();

    if($nbPsgr > count($passengers)) {
    }
    elseif($nbPsgr < count($passengers)) {
    }

    return $passengers;
  }
}

