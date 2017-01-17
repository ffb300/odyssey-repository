<?php
/**
 * @package Odyssey
 * @copyright Copyright (c) 2016 Lucas Sanner
 * @license GNU General Public License version 3, or later
 */

defined('_JEXEC') or die;

jimport('joomla.application.component.view');
require_once JPATH_COMPONENT_SITE.'/helpers/route.php';
require_once JPATH_COMPONENT_SITE.'/helpers/travel.php';
require_once JPATH_COMPONENT_SITE.'/helpers/pricerule.php';
require_once JPATH_COMPONENT_ADMINISTRATOR.'/helpers/utility.php';

/**
 * HTML View class for the Odyssey component.
 */
class OdysseyViewSearch extends JViewLegacy
{
  protected $items;
  protected $state;
  protected $pagination;
  public $menuParams;
  public $config;
  public $currency;


  public function display($tpl = null)
  {
    $this->items = $this->get('Items');
    $this->state = $this->get('State');
    $this->pagination = $this->get('Pagination');
    $this->filterForm = $this->get('FilterForm');
    $this->activeFilters = $this->get('ActiveFilters');

    // Check for errors.
    if(count($errors = $this->get('Errors'))) {
      JError::raiseError(500, implode('<br />', $errors));
      return false;
    }

    //Get the needed parameters for the thumbnail layout.
    if($this->getLayout() == 'thumbnail') {
      $app = JFactory::getApplication();
      $active = $app->getMenu()->getActive();
      $menus = $app->getMenu();
      $this->menuParams = $menus->getParams($active->id);

      $rate = 0;
      if(ctype_digit($this->menuParams->get('reduction_rate'))) {
	$rate = $this->menuParams->get('reduction_rate');
      }
    }

    $itemIds = $catIds = array();
    // Compute the travel slugs.
    foreach($this->items as $item) {
      $item->slug = $item->alias ? ($item->id.':'.$item->alias) : $item->id;

      if($this->getLayout() == 'thumbnail') {
	//First check for a valid image file.
	if(empty($item->image) || !is_file($item->image)) {
	  //Set the default image.
	  $item->image = 'media/com_odyssey/images/camera-icon.jpg';
	}

	//Get the image width and height then retrieve the new image size according to the
	//reduction rate.
	$imageSize = getimagesize($item->image);
	$size = TravelHelper::getThumbnailSize($imageSize[0], $imageSize[1], $rate);
	$item->img_width = $size['width'];
	$item->img_height = $size['height'];
      }

      //Collect travel and category ids.
      $itemIds[] = $item->id;
      if(!in_array($item->catid, $catIds)) {
	$catIds[] = $item->catid;
      }
    }

    if(!empty($itemIds)) {
      //Get possible price rules.
      $pricesStartingAtPrules = PriceruleHelper::getPricesStartingAt($itemIds, $catIds);

      foreach($this->items as $item) {
	//Set the possible price rules for each travel.
	foreach($pricesStartingAtPrules as $travelId => $priceStartingAtPrules) {
	  if($travelId == $item->id) {
	    $item->price_prules = $priceStartingAtPrules;
	  }
	}
      }
    }

    $this->config = JComponentHelper::getParams('com_odyssey');
    $this->currency = UtilityHelper::getCurrency();

    $this->setDocument();

    parent::display($tpl);
  }


  protected function setDocument() 
  {
    //Include css files (if needed).
    $doc = JFactory::getDocument();
    $doc->addStyleSheet(JURI::base().'components/com_odyssey/css/odyssey.css');
  }
}

