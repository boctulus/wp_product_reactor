<?php
/*
Plugin Name: Reactor
Description: Product monitor
Version: 1.125
Author: boctul.us@gmail.com <Pablo>
*/

use reactor\libs\Debug;
use reactor\libs\Files;
use reactor\libs\Url;

/*
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

require_once __DIR__ . '/libs/Debug.php';
require_once __DIR__ . '/libs/Url.php';
require_once __DIR__ . '/rest.php';

require_once __DIR__ . '/../../../wp-admin/includes/export.php';


if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}


if (!function_exists('dd')){
	function dd($val, $msg = null, $pre_cond = null){
		Debug::dd($val, $msg, $pre_cond);
	}
}

/**
 * Check if WooCommerce is active
 */
if ( !in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}


function reactor_installer(){
    include('installer.php');
}

register_activation_hook(__file__, 'reactor_installer');


class Reactor 
{
	protected $action = null;
	protected static $config;
	protected $woocommerce;

	function __construct()
	{
		self::getConfig();

		require_once __DIR__ . '/libs/Files.php';	

		add_action('woocommerce_update_product', [$this, 'sync_on_product_update'], 11, 1 );
		add_action('added_post_meta', [$this, 'sync_on_new_post_data'], 10, 4 );
		add_action('untrash_post', [$this, 'sync_on_untrash_post'], 10, 1);
	}	


	static function getConfig(){
		if (self::$config != null){
            return self::$config;
        }

		self::$config = include __DIR__ . '/config/config.php';
		return self::$config;
	}

	/*
		$product es el objeto producto
		$taxonomy es opcional y es algo como 'pa_talla'
	*/
	static function getVariationAttributes($product, $taxonomy = null){
		$attr = [];

		if ( $product->get_type() == 'variable' ) {
			foreach ($product->get_available_variations() as $values) {
				foreach ( $values['attributes'] as $attr_variation => $term_slug ) {
					if (!isset($attr[$attr_variation])){
						$attr[$attr_variation] = [];
					}

					if ($taxonomy != null){
						if( $attr_variation === 'attribute_' . $taxonomy ){
							if (!in_array($term_slug, $attr[$attr_variation])){
								$attr[$attr_variation][] = $term_slug;
							}                        
						}
					} else {
						if (!in_array($term_slug, $attr[$attr_variation])){
							$attr[$attr_variation][] = $term_slug;
						} 
					}

				}
			}
		}

		$arr = [];
		foreach ($attr as $name => $a){
			$key = 'pa_' .substr($name, 13);
			foreach ($a as $e){
				$arr[$key]['term_names'][] = $e;
			}

			$arr[$key]['is_visible'] = true; 
		}

		/*
			array(
				// Taxonomy and term name values
				'pa_color' => array(
					'term_names' => array('Red', 'Blue'),
					'is_visible' => true,
					'for_variation' => false,
				),
				'pa_tall' =>  array(
					'term_names' => array('X Large'),
					'is_visible' => true,
					'for_variation' => false,
				),
			),
  		*/
		return $arr;
	}

	static function getTagsByPid($pid){
		global $wpdb;

		$pid = (int) $pid;

		$sql = "SELECT T.name, T.slug FROM wp_term_relationships as TR 
		INNER JOIN `{$wpdb->prefix}term_taxonomy` as TT ON TR.term_taxonomy_id = TT.term_id  
		INNER JOIN `{$wpdb->prefix}terms` as T ON  TT.term_taxonomy_id = T.term_id
		WHERE taxonomy = 'product_tag' AND TR.object_id='$pid'";

		return $wpdb->get_results($sql);
	}

	static function dumpProduct($product){
		$obj = [];

		if (static::getConfig()['debug']){
			Files::dump($product, 'raw.txt');
		}
	
		$get_src = function($html) {
			$parsed_img = json_decode(json_encode(simplexml_load_string($html)), true);
			$src = $parsed_img['@attributes']['src']; 
			return $src;
		};
	
		// Get Product General Info
	  
		$pid = $product->get_id();

		$obj['id'] = $pid;;
		$obj['type'] = $product->get_type();
		$obj['name'] = $product->get_name();
		$obj['slug'] = $product->get_slug();
		$obj['status'] = $product->get_status();
		$obj['featured'] = $product->get_featured();
		$obj['catalog_visibility'] = $product->get_catalog_visibility();
		$obj['description'] = $product->get_description();
		$obj['short_description'] = $product->get_short_description();
		$obj['sku'] = $product->get_sku();
		#$obj['virtual'] = $product->get_virtual();
		#$obj['permalink'] = get_permalink( $product->get_id() );
		#$obj['menu_order'] = $product->get_menu_order(
		#$obj['date_created'] = $product->get_date_created();
		#$obj['date_modified'] = $product->get_date_modified();
		
		// Get Product Prices
		
		$obj['price'] = $product->get_price();
		$obj['regular_price'] = $product->get_regular_price();
		$obj['sale_price'] = $product->get_sale_price();
		#$obj['date_on_sale_from'] = $product->get_date_on_sale_from();
		#$obj['date_on_sale_to'] = $product->get_date_on_sale_to();
		#$obj['total_sales'] = $product->get_total_sales();
		
		// Get Product Tax, Shipping & Stock
		
		#$obj['tax_status'] = $product->get_tax_status();
		#$obj['tax_class'] = $product->get_tax_class();
		$obj['manage_stock'] = $product->get_manage_stock();
		$obj['stock_quantity'] = $product->get_stock_quantity();
		$obj['stock_status'] = $product->get_stock_status();
		#$obj['backorders'] = $product->get_backorders();
		$obj['is_sold_individually'] = $product->get_sold_individually();
		#$obj['purchase_note'] = $product->get_purchase_note();
		#$obj['shipping_class_id'] = $product->get_shipping_class_id();
		
		// Get Product Dimensions
		
		$obj['weight'] = $product->get_weight();
		$obj['length'] = $product->get_length();
		$obj['width'] = $product->get_width();
		$obj['height'] = $product->get_height();
		//	$obj['dimensions'] = $product->get_dimensions(false);
		
		// Get Linked Products
		
		#$obj['upsell_ids'] = $product->get_upsell_ids();
		#$obj['cross_sell_id'] = $product->get_cross_sell_ids();
		$obj['parent_id'] = $product->get_parent_id();
		
		// Get Product Taxonomies
		
		$obj['tags'] = self::getTagsByPid($pid);


		$obj['categories'] = [];
		$category_ids = $product->get_category_ids();
	
		foreach ($category_ids as $cat_id){
			$terms = get_term_by( 'id', $cat_id, 'product_cat' );
			$obj['categories'][] = [
				'name' => $terms->name,
				'slug' => $terms->slug,
				'description' => $terms->description
			];
		}
			
		
		// Get Product Downloads
		
		#$obj['downloads'] = $product->get_downloads();
		#$obj['download_expiry'] = $product->get_download_expiry();
		#$obj['downloadable'] = $product->get_downloadable();
		#$obj['download_limit'] = $product->get_download_limit();
		
		// Get Product Images
		
		#$obj['image_id'] = $product->get_image_id();
		$obj['image'] =  wp_get_attachment_image_src($product->get_image_id(), 'large');  

		$gallery_image_ids = $product->get_gallery_image_ids();
			
		$obj['gallery_images'] = [];
		foreach ($gallery_image_ids as $giid){
			$obj['gallery_images'][] = wp_get_attachment_image_src($giid, 'large');
		}	
	
		// Get Product Reviews
		
		#$obj['reviews_allowed'] = $product->get_reviews_allowed();
		#$obj['rating_counts'] = $product->get_rating_counts();
		#$obj['average_rating'] = $product->get_average_rating();
		#$obj['review_count'] = $product->get_review_count();
	
		// Get Product Variations and Attributes

		if($obj['type'] == 'variable'){
			$variation_ids = $product->get_children(); // get variations
	
			$obj['attributes'] = self::getVariationAttributes($product);
			
			$tmp = $product->get_default_attributes();
			if (!empty($tmp)){
				$obj['default_attributes'] = $tmp;
			}		


			$obj['variations'] = $product->get_available_variations();	
			
			foreach ($obj['variations'] as $k => $var){

				if ($var['sku'] == $obj['sku']){
					$obj['variations'][$k]['sku'] = '';
				}
				
			}

			
		} else {
			// Simple product

			$obj['attributes'] = $product->get_attributes();
		}		

		if (static::getConfig()['debug']){
			Files::dump($obj, 'procesado.txt');
		}
	
		return $obj;		
	}


	static function toStack($product, $product_id, $sku, $operation)
	{
		global $wpdb;

		$config = static::$config;

		if (empty($product_id) || !is_numeric($product_id)){
			throw new \InvalidArgumentException("ID de producto $product_id es inválido");
		}

		if ($sku == null){
			throw new \InvalidArgumentException("Sku $sku no puede ser nulo");
		}

		if (! in_array($operation, ['UPDATE', 'DELETE', 'CREATE', 'RESTORE'])){
			throw new \InvalidArgumentException("Operation $operation is invalid");
		}

		$error = false;
		if ($config['send_now']){
			$url = $config['url'] . '/index.php/wp-json/connector/v1/woocommerce/products?api_key=' . $config['API_KEY'];

			$p = Reactor::dumpProduct($product);

        	$p['operation'] = $operation;

			try {
				$res = Url::consume_api($url, 'POST', $p);
			} catch (\Exception $e) {
				//dd($e->getMessage());
				$error = true;
			}
		}

		$affected = false;

		if (!$config['send_now'] || $error){
			$affected = $wpdb->query("INSERT INTO `{$wpdb->prefix}product_updates` (`product_id`, `sku`, `operation`) 
			VALUES ($product_id, '$sku', '$operation')
			ON DUPLICATE KEY UPDATE
			`operation` = '$operation';");
		}

		return $affected;
	}

	/*
		array (
			0 => 
			(object) array(
				'id' => '10',
				'sku' => 'yyy',
				'operation' => 'DELETE',
			),
			1 => 
			(object) array(
				'id' => '13',
				'sku' => 'elefante-panta-1',
				'operation' => 'RESTORE',
			),
			2 => 
			(object) array(
				'id' => '15',
				'sku' => 'novo-1',
				'operation' => 'CREATE',
			),
		)
	*/
	static function getStack(){
		global $wpdb;

		$sql = "SELECT * FROM `{$wpdb->prefix}product_updates`";

		return $wpdb->get_results($sql);
	}

	static function clearStack(Array $ids){
		global $wpdb;

		if (empty($ids)){
			return;
		}

		$ids_str = implode(',', $ids);
		$sql = "DELETE FROM `{$wpdb->prefix}product_updates` WHERE id IN ($ids_str)";
				
		return $wpdb->query($sql);
	}

	/*
		Event Hooks
	*/
	
	function onCreate($product){
		$pid = $product->get_id();
		$sku = $product->get_sku();

		if ($sku == null){
			return;
		}

		if (!empty(get_transient('product-'. $pid))){
			return;
		}

		set_transient('product-'. $pid, true, 2);
		
		self::toStack($product, $pid, $sku, 'CREATE');
	}

	function onUpdate($product){
		$pid = $product->get_id();
		$sku = $product->get_sku();

		if ($sku == null){
			return;
		}		
		
		if (!empty(get_transient('product-'. $pid))){
			return;
		}

		set_transient('product-'. $pid, true, 2);

		$affected = self::toStack($product, $pid, $sku, 'UPDATE');	

		#$obj = $this->dumpProduct($product);
		#Files::dump($obj);
		//exit;
	}

	function onDelete($product){
		$pid = $product->get_id();
		$sku = $product->get_sku();

		if ($sku == null){
			return;
		}

		self::toStack($product, $pid, $sku, 'DELETE');
	}

	function onRestore($product){
		$pid = $product->get_id();
		$sku = $product->get_sku();

		if ($sku == null){
			return;
		}

		self::toStack($product, $pid, $sku, 'RESTORE');
	}
	
	//////////////////////////////////////////////


	function sync_on_product_update($product_id) {
		$this->action = 'edit';
		$product = wc_get_product( $product_id );
		$this->onUpdate($product);
	}

	function sync_on_untrash_post($pid){
		if (get_post_type($pid) != 'product'){
			return;
		}

		$this->action = 'untrash';
		$product = wc_get_product($pid);
		$this->onRestore($product);
	}

	function sync_on_new_post_data($meta_id, $post_id, $meta_key, $meta_value) {  
		if (get_post_type($post_id) == 'product') 
		{ 
			/*
				$meta_key == 
				
				_wp_trash_meta_status  => es borrado
				_wp_old_slug => restaurado
				_stock => editado
			*/

			// si ya lo cogió el otro hook
			if ($this->action == 'edit'){
				return;
			}

			//  draft y otros no me interesan
			if ($meta_value != 'publish'){
				return;
			}

			$product = wc_get_product( $post_id );

			switch ($meta_key){
				case '_wp_trash_meta_status': 
					$this->action = 'trash';
					$this->onDelete($product);
					break;
				case '_wp_old_slug':
					$this->action = 'restore';
					$this->onRestore($product);
					break;
				case '_stock':
					$this->action = 'edit';
					$this->onUpdate($product);
					break;
				// creación
				default:
					$this->action = 'create';
					$this->onCreate($product);
			}
		}
	
	}
}


$reactor = new Reactor();
