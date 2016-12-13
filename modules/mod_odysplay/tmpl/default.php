<?php
/**
 * @package Odyssey
 * @copyright Copyright (c)2016 - 2016 Lucas Sanner
 * @license GNU General Public License version 3, or later
 */

defined('_JEXEC') or die; // No direct access.

//Get the component settings.
$parameters = JComponentHelper::getParams('com_odyssey');
$digitsPrecision = $parameters->get('digits_precision');

//Include css file.
$css = JFactory::getDocument();
$css->addStyleSheet(JURI::base().'modules/mod_odysplay/odysplay.css');
?>

<div class="odysplay-module">
<?php if($module->showtitle) : ?>
  <h1 class="module-title"><?php echo $module->title; ?></h1>
<?php endif; ?>

<?php foreach($travels as $travel) : 
	$link = JRoute::_(OdysseyHelperRoute::getTravelRoute($travel->slug, $travel->catid, $travel->language));
	//var_dump($travel->tags);
?>
  <div class="odysplay-travel">
    <?php if($params->get('show_name')) : ?>
      <?php if($params->get('linked_name')) : ?>
	<a href="<?php echo $link; ?>">
      <?php endif; ?>
	<h2 class="travel-name"><?php echo ModOdysplayHelper::getMaxCharacters($travel->name, $params->get('name_max_char')); ?></h2>
      <?php if($params->get('linked_name')) : ?>
	</a>
      <?php endif; ?>
    <?php endif; ?>

    <?php if($params->get('show_image')) :
	    if($params->get('link_image')) : ?>
      <a href="<?php echo $link; ?>">
	  <?php endif; ?>
	<img src="<?php echo $travel->image; ?>" width="<?php echo $travel->img_width; ?>"
	     height="<?php echo $travel->img_height; ?>" class="travel-image" />
	<?php if($params->get('link_image')) : ?>
	  </a>
	<?php endif; ?>
    <?php endif; ?>

    <?php if($params->get('show_introtext')) : ?>
      <div class="travel-text"><?php echo ModOdysplayHelper::getMaxCharacters($travel->intro_text, $params->get('text_max_char')); ?></div>
    <?php endif; ?>

    <?php if($params->get('show_travel_duration')) : ?>
      <div class="travel-duration"><?php echo JText::_('MOD_ODYSPLAY_OPTION_TRAVEL_DURATION_'.$travel->travel_duration); ?></div>
    <?php endif; ?>

    <?php if($params->get('show_price')) : ?>
      <div class="travel-price">
	<span class="item-field"><?php echo JText::_('MOD_ODYSPLAY_PRICE_STARTING_AT'); ?></span>
	<?php if(isset($travel->price_starting_at_prules) && $travel->price_starting_at_prules['price'] < $travel->price_starting_at) : ?>
	  <span class="normal-price">
	    <?php echo UtilityHelper::formatNumber($travel->price_starting_at_prules['normal_price'], $digitsPrecision); ?></span>
	  <span class="price-starting-at">
	    <?php echo UtilityHelper::formatNumber($travel->price_starting_at_prules['price'], $digitsPrecision); ?></span>
	<?php else : ?>
	  <span class="price-starting-at"><?php echo UtilityHelper::formatNumber($travel->price_starting_at, $digitsPrecision); ?></span>
	<?php endif; ?>
	<span class="currency"><?php echo $currency; ?></span>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>

