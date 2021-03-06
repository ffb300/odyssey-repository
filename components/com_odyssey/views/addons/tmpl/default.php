<?php
/**
 * @package Odyssey
 * @copyright Copyright (c) 2016 - 2017 Lucas Sanner
 * @license GNU General Public License version 3, or later
 */

// no direct access
defined('_JEXEC') or die;

JHtml::addIncludePath(JPATH_COMPONENT.'/helpers');

$addons = $this->addonData['addons'];
$addonOptions = $this->addonData['addon_options'];
$addonPrules = $this->addonData['addon_prules'];
$addonOptionPrules = $this->addonData['addon_option_prules'];
$prevRadioChecked = array();

//Ensure first that there is at least one hosting addon (matching with the number of
//passengers selected by the customer).
$isHosting = false;
foreach($addons as $addon) {
  if($addon['addon_type'] == 'hosting') {
    $isHosting = true;
    break;
  }
}

//Grab the user session.
$session = JFactory::getSession();
$travel = $session->get('travel', array(), 'odyssey'); 
$settings = $session->get('settings', array(), 'odyssey'); 
//echo '<pre>';
//var_dump($travel);
//echo '</pre>';

?>

<?php echo JLayoutHelper::render('booking_breadcrumb', array('position' => 'addons', 'travel' => $travel),
                                  JPATH_SITE.'/components/com_odyssey/layouts/'); ?>

<?php if($isHosting) : ?>

  <?php echo JLayoutHelper::render('booking_summary', array('travel' => $travel, 'settings' => $settings),
				    JPATH_SITE.'/components/com_odyssey/layouts/'); ?>

  <form action="index.php?option=com_odyssey&task=addons.setAddons" method="post" name="addons" id="addons">
  <?php

  foreach($addons as $key => $addon) {
    //Create an opening div for the very first step.
    if($key == 0) {
      echo '<div class="addon-step">';
      echo '<h3>'.$addon['step_name'].'</h3>';
    }
    //Close the previous step and create an opening div for the new one.
    //Note: Check and separate the global addons set in the departure set.
    elseif(($key > 0 && $addon['step_id'] != $addons[$key - 1]['step_id']) || (!$addon['global'] && $addons[$key - 1]['global'])) {
      echo '</div><div class="addon-step">';
      echo '<h3>'.$addon['step_name'].'</h3>';
    }

    $normalPrice = $price = $addon['price'];
    //Check price rules for this addon.
    $isPriceRule = false;
    $priceRuleNames = array();
    if(isset($addonPrules[$addon['step_id']][$addon['addon_id']])) {
      foreach($addonPrules[$addon['step_id']][$addon['addon_id']] as $addonPrule) {
	//Get the new price. 
	$price = PriceruleHelper::computePriceRule($addonPrule['operation'], $addonPrule['value'], $price);

	if($addonPrule['show_rule']) {
	  //Store the price rule names in an array so that they can be displayed out of
	  //the scope.
	  if(isset($priceRuleNames[$addon['step_id'].'-'.$addon['addon_id']])) {
	    //Add another price rule name.
	    $priceRuleNames[$addon['step_id'].'-'.$addon['addon_id']] .= '<div class="pricerule-name">'.$addonPrule['name'].
	                                                                 ' <span class="pricerule-value">'.
									  UtilityHelper::formatPriceRule($addonPrule['operation'], 
									                                 $addonPrule['value']).'</span></div>';
	  }
	  else {
	    $priceRuleNames[$addon['step_id'].'-'.$addon['addon_id']] = '<div class="pricerule-name">'.$addonPrule['name'].
	                                                                ' <span class="pricerule-value">'.
									 UtilityHelper::formatPriceRule($addonPrule['operation'], 
									                                $addonPrule['value']).'</span></div>';
	  }

	  $isPriceRule = true;
	}
	else { //Hidden price rule.
	  //We applied the hidden price rule values to the normal 
	  //price so that there is no misunderstanding about the 
	  //computing price in case other price rules are shown.
	  $normalPrice = PriceruleHelper::computePriceRule($addonPrule['operation'], $addonPrule['value'], $normalPrice);
	}

	//Don't go further in case of Exclusive price rule.
	if($addonPrule['behavior'] == 'XOR') {
	  break;
	}
      }
    }

    //The addon has no group.
    if($addon['group_nb'] == 'none') {
      //Display information and price.
      echo '<div class="addon">'.
	   '<h2 class="addon-title">'.$this->escape($addon['name']).'</h2>'.
	   '<div class="addon-description">'.$addon['description'].'</div>';

      if(!empty($addon['image'])) {
	echo '<div class="addon-image"><img src="'.$addon['image'].'" /></div>';
      }

      if($addon['price'] > 0) {
	//Check for price rules.
	if($isPriceRule) {
	  if(isset($priceRuleNames[$addon['step_id'].'-'.$addon['addon_id']])) {
	    echo $priceRuleNames[$addon['step_id'].'-'.$addon['addon_id']];
	  }

	  echo '<div class="addon-price"><span class="normal-price">'.
		UtilityHelper::formatNumber($normalPrice).'</span><span class="currency">'.$this->currency.'</span></div>';
	}

	echo '<div class="addon-price">'.
	     '<span class="price">'.UtilityHelper::formatNumber($price).'</span>'.
	     '<span class="currency">'.$this->currency.'</span></div>';
      }
      //Use a hidden type tag as there is no selection for this addon.
      //Note: Value attribute is useless here as we get all the required ids from the name.
      echo '<input type="hidden" name="none_'.$addon['step_id'].'_'.$addon['addon_id'].'" value="'.$addon['addon_id'].'" >';

      //Check for addon options.
      if(!empty($addon['option_type'])) {
	echo JLayoutHelper::render('addon_options', array('addon_options' => $addonOptions, 
							  'addon' => $addon,
							  'addon_option_prules' => $addonOptionPrules, 
							  'currency' => $this->currency), JPATH_SITE.'/components/com_odyssey/layouts/');
      }

      echo '</div>';
    }
    else { //Addons belong to a group.
      //Parse the group_nb value to get the group number as well as the selection type.
      preg_match('#^([0-9]+)\:(no_sel|single_sel|multi_sel)$#', $addon['group_nb'], $matches);
      $grpNb = $matches[1];
      $selType = $matches[2];
      //The first radio button of a group (single select) is checked by default.
      $checked = ' checked="checked"';

      //The previous addon belongs to the same step and group than the current one.
      if(isset($addons[$key - 1]) && $addons[$key - 1]['step_id'] == $addon['step_id'] && $addons[$key - 1]['group_nb'] == $addon['group_nb']) {
	$checked = '';
      }
      else { //It's the first addon of the group.
	echo '<div class="addon-group">';
      }

      //Display information and price.
      echo '<div class="addon">'.
	   '<h2 class="addon-title">'.$this->escape($addon['name']).'</h2>'.
	   '<div class="addon-description">'.$addon['description'].'</div>';

      if(!empty($addon['image'])) {
	echo '<div class="addon-image"><img src="'.$addon['image'].'" /></div>';
      }

      if($addon['price'] > 0) {
	//Check for price rules.
	if($isPriceRule) {
	  if(isset($priceRuleNames[$addon['step_id'].'-'.$addon['addon_id']])) {
	    echo $priceRuleNames[$addon['step_id'].'-'.$addon['addon_id']];
	  }

	  echo '<div class="addon-price"><span class="normal-price">'.
		UtilityHelper::formatNumber($normalPrice).'</span><span class="currency">'.$this->currency.'</span></div>';
	}

	echo '<div class="addon-price">'.
	     '<span class="price">'.UtilityHelper::formatNumber($price).'</span>'.
	     '<span class="currency">'.$this->currency.'</span></div>';
      }

      //Set the addon tag according to the selection type.
      if($selType == 'single_sel') {
	//Note: Ids are set differently for single selection (radio buttons).
	echo '<input type="radio" class="single" name="single_'.$grpNb.'_'.$addon['step_id'].'" value="'.$addon['addon_id'].'" '.$checked.'>';
	//Used for the dynamical addon prices Javascript function. 
	echo '<input type="hidden" name="js_addon_prices" id="js_single_'.$grpNb.'_'.$addon['step_id'].'_'.$addon['addon_id'].'" value="'.$price.'" disabled>';

	//Detects wether the tag used with js to get the previous checked button value has been created.
	if(!in_array('js_prev_radio_checked_'.$grpNb.'_'.$addon['step_id'], $prevRadioChecked)) {
	  //Stores the tag id as it must be created just once.
	  $prevRadioChecked[] = 'js_prev_radio_checked_'.$grpNb.'_'.$addon['step_id'];
	  //Used by Javascript to store the value (ie: the addon id) of the previous checked button in this group.
	  echo '<input type="hidden" name="js_addon_prices" id="js_prev_radio_checked_'.$grpNb.'_'.$addon['step_id'].'" value="'.$addon['addon_id'].'" disabled>';
	}
      }
      elseif($selType == 'multi_sel') {
	echo '<input type="checkbox" class="multi" name="multi_'.$grpNb.'_'.$addon['step_id'].'[]" value="'.$addon['addon_id'].'" >';
	//Used for the dynamical addon prices Javascript function. 
	echo '<input type="hidden" name="js_addon_prices" id="js_multi_'.$grpNb.'_'.$addon['step_id'].'_'.$addon['addon_id'].'" value="'.$price.'" disabled>';
      }
      else { //no_sel
	//Use a hidden type tag as there is no selection for this addon.
	echo '<input type="hidden" name="no_'.$grpNb.'_'.$addon['step_id'].'" value="'.$addon['addon_id'].'" >';
      }

      //Check for addon options.
      if(!empty($addon['option_type'])) {
	echo JLayoutHelper::render('addon_options', array('addon_options' => $addonOptions, 
							  'addon' => $addon, 
							  'addon_option_prules' => $addonOptionPrules, 
							  'currency' => $this->currency), JPATH_SITE.'/components/com_odyssey/layouts/');
      }

      echo '</div>'; //Close the addon div.

      //The current addon is the last addon of the group.
      if(!isset($addons[$key + 1]) || $addons[$key + 1]['step_id'] != $addon['step_id'] || $addons[$key + 1]['group_nb'] != $addon['group_nb']) {
	echo '</div>'; //Close the addon group div.
      }
    }

    //The current step is the last one.
    if(!isset($addons[$key + 1])) {
      echo '</div>'; //Close the step div.
    }
  }
  ?>
    <div id="btn-message">
      <input type="submit" class="btn btn-warning" onclick="hideButton('btn')" value="<?php echo JText::_('COM_ODYSSEY_BUTTON_NEXT'); ?>" />
    </div>
  </form>
<?php else : //No hosting addon. ?>
  <div class="no-hosting">
    <?php echo JText::sprintf('COM_ODYSSEY_NO_HOSTING_ADDON_AVAILABLE', $travel['nb_psgr']); ?>
  </div>
<?php endif; ?>

<?php
JHtml::_('jquery.framework');
//Load the jQuery scripts.
$doc = JFactory::getDocument();
$doc->addScript(JURI::base().'components/com_odyssey/js/addonprices.js');

