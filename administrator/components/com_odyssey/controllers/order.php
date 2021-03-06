<?php
/**
 * @package Odyssey
 * @copyright Copyright (c) 2016 - 2017 Lucas Sanner
 * @license GNU General Public License version 3, or later
 */

defined('_JEXEC') or die; //No direct access to this file.
 
jimport('joomla.application.component.controllerform');
 


class OdysseyControllerOrder extends JControllerForm
{

  public function save($key = null, $urlVar = null)
  {
    //Get the jform data.
    $data = $this->input->post->get('jform', array(), 'array');
    $post = JFactory::getApplication()->input->post->getArray();
echo '<pre>';
//var_dump($post);
echo '</pre>';
//return;
    //Reset the jform data array 
    //$this->input->post->set('jform', $data);

    //Hand over to the parent function.
    return parent::save($key = null, $urlVar = null);
  }


  //Overrided function.
  protected function allowEdit($data = array(), $key = 'id')
  {
    $itemId = $data['id'];
    $user = JFactory::getUser();

    //Get the item owner id.
    $db = JFactory::getDbo();
    $query = $db->getQuery(true);
    $query->select('customer_id')
	  ->from('#__odyssey_order')
	  ->where('id='.(int)$itemId);
    $db->setQuery($query);
    $customerId = $db->loadResult();

    $canEdit = $user->authorise('core.edit', 'com_odyssey');
    $canEditOwn = $user->authorise('core.edit.own', 'com_odyssey') && $customerId == $user->id;

    //Allow edition. 
    if($canEdit || $canEditOwn) {
      return 1;
    }

    //Hand over to the parent function.
    return parent::allowEdit($data = array(), $key = 'id');
  }
}

