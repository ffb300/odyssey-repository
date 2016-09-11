<?php

//Initialize the Joomla framework
define('_JEXEC', 1);
//First we get the number of letters we want to substract from the path.
$length = strlen('/administrator/components/com_odyssey/js');
//Turn the length number into a negative value.
$length = $length - ($length * 2);
//
define('JPATH_BASE', substr(dirname(__DIR__), 0, $length));

//Get the required files
require_once (JPATH_BASE.'/includes/defines.php');
require_once (JPATH_BASE.'/includes/framework.php');
//We need to use Joomla's database class 
require_once (JPATH_BASE.'/libraries/joomla/factory.php');
//Create the application
$mainframe = JFactory::getApplication('site');
$mainframe->initialise();

//Get the required variables.
$travelId = JFactory::getApplication()->input->get->get('travel_id', 0, 'uint');
$dptStepId = JFactory::getApplication()->input->get->get('dpt_step_id', 0, 'uint');

$data = array();

$db = JFactory::getDbo();
$query = $db->getQuery(true);

$query->select('ts.dpt_step_id, ts.ordering AS sequence_ordering, s.name AS sequence_name')
      ->from('#__odyssey_travel_dpt_step_map AS ts')
      ->join('LEFT', '#__odyssey_step AS s ON s.id=ts.dpt_step_id')
      ->where('ts.travel_id='.(int)$travelId)
      ->order('ts.ordering');
$db->setQuery($query);
$sequences = $db->loadAssocList();

$data['sequence'] = $sequences;


echo json_encode($data);
