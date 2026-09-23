<?php
/**
 * Step 2: Generate ~500 clothing products.
 * Run setup-taxonomy.php FIRST — this script only looks up categories
 * and attribute terms, it never creates them.
 *
 * Run:
 *   docker compose run --rm wpcli wp eval-file generate-products.php
 *
 * Safe to re-run: skips any SKU that already exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run this via wp-cli only.\n";
	exit( 1 );
}

// ---------------------------------------------------------------------
// CONFIG
// ---------------------------------------------------------------------
$TARGET_PRODUCTS = 500;
$VARIABLE_RATIO  = 0.30;

$category_tree = array(
	'Men'         => array( 'T-Shirts', 'Shirts', 'Jeans', 'Trousers', 'Jackets', 'Hoodies' ),
	'Women'       => array( 'Dresses', 'Tops', 'Jeans', 'Trousers', 'Jackets', 'Kurtis' ),
	'Kids'        => array( 'T-Shirts', 'Shirts', 'Dresses', 'Jeans' ),
	'Accessories' => array( 'Belts', 'Caps', 'Bags', 'Socks' ),
);
$variable_eligible = array( 'T-Shirts', 'Shirts', 'Jeans', 'Trousers', 'Jackets', 'Hoodies', 'Dresses', 'Tops', 'Kurtis' );

$sizes  = array( 'XS', 'S', 'M', 'L', 'XL', 'XXL' );
$colors = array( 'Black', 'White', 'Navy', 'Grey', 'Red', 'Olive', 'Beige', 'Maroon', 'Sky Blue', 'Mustard' );
$brands = array( 'Urbane Threads', 'Coastline Co.', 'Northgate Apparel', 'Pixel & Thread', 'Meridian Wear', 'Larkspur', 'Foundry Denim', 'Cobalt Row', 'Aster & Vine', 'Hemlock Supply' );

$style_words = array( 'Classic', 'Slim Fit', 'Relaxed', 'Regular Fit', 'Casual', 'Premium', 'Everyday', 'Essential', 'Signature', 'Comfort' );

$price_ranges = array(
	'T-Shirts' => array( 399, 999 ),   'Shirts'  => array( 699, 1799 ),
	'Jeans'    => array( 999, 2499 ),  'Trousers'=> array( 799, 1999 ),
	'Jackets'  => array( 1499, 3999 ), 'Hoodies' => array( 999, 2499 ),
	'Dresses'  => array( 899, 2999 ),  'Tops'    => array( 499, 1499 ),
	'Kurtis'   => array( 699, 1999 ),  'Belts'   => array( 299, 899 ),
	'Caps'     => array( 199, 599 ),   'Bags'    => array( 799, 2499 ),
	'Socks'    => array( 99, 299 ),
);

$tag_pool = array( 'New Arrival', 'Bestseller', 'Trending', 'Summer', 'Winter', 'Casual', 'Formal', 'Sale', 'Limited Edition', 'Everyday Essential' );

// ---------------------------------------------------------------------
// LOOKUP-ONLY HELPERS (fail fast — this script never creates taxonomy data)
// ---------------------------------------------------------------------
function get_term_id_or_die( $name, $taxonomy, $parent = 0 ) {
	$existing = term_exists( $name, $taxonomy, $parent ?: null );
	if ( ! $existing ) {
		WP_CLI::error( "Missing term '$name' in '$taxonomy'. Run setup-taxonomy.php first." );
	}
	return (int) $existing['term_id'];
}

function get_or_create_tag_id( $name ) {
	// product_tag is a stock WP taxonomy, always registered — safe to create on the fly.
	$existing = term_exists( $name, 'product_tag' );
	if ( $existing ) {
		return (int) $existing['term_id'];
	}
	$result = wp_insert_term( $name, 'product_tag' );
	return is_wp_error( $result ) ? 0 : (int) $result['term_id'];
}

// ---------------------------------------------------------------------
// 0. Sanity check
// ---------------------------------------------------------------------
foreach ( array( 'pa_size', 'pa_color', 'product_brand' ) as $tax ) {
	if ( ! taxonomy_exists( $tax ) ) {
		WP_CLI::error( "Taxonomy '$tax' not found. Run setup-taxonomy.php first." );
	}
}

// ---------------------------------------------------------------------
// 1. Look up category IDs (created by setup-taxonomy.php)
// ---------------------------------------------------------------------
$category_ids = array();
foreach ( $category_tree as $dept => $subs ) {
	$dept_id               = get_term_id_or_die( $dept, 'product_cat' );
	$category_ids[ $dept ] = $dept_id;
	foreach ( $subs as $sub ) {
		$category_ids["$dept>$sub"] = get_term_id_or_die( $sub, 'product_cat', $dept_id );
	}
}

// ---------------------------------------------------------------------
// 2. Look up attribute term IDs (created by setup-taxonomy.php)
// ---------------------------------------------------------------------
$size_map  = array();
$color_map = array();
$brand_map = array();
foreach ( $sizes as $s ) {
	$size_map[ $s ] = get_term_id_or_die( $s, 'pa_size' );
}
foreach ( $colors as $c ) {
	$color_map[ $c ] = get_term_id_or_die( $c, 'pa_color' );
}
foreach ( $brands as $b ) {
	$brand_map[ $b ] = get_term_id_or_die( $b, 'product_brand' );
}
WP_CLI::log( 'Categories and attributes verified. Generating products...' );

// ---------------------------------------------------------------------
// 3. Build slot list (one slot per department/subcategory, weighted to hit target)
// ---------------------------------------------------------------------
$slots = array();
foreach ( $category_tree as $dept => $subs ) {
	foreach ( $subs as $sub ) {
		$slots[] = array( $dept, $sub );
	}
}
$per_slot  = (int) floor( $TARGET_PRODUCTS / count( $slots ) );
$remainder = $TARGET_PRODUCTS - ( $per_slot * count( $slots ) );

// ---------------------------------------------------------------------
// 4. Generate products
// ---------------------------------------------------------------------
$created  = 0;
$progress = \WP_CLI\Utils\make_progress_bar( 'Creating products', $TARGET_PRODUCTS );

foreach ( $slots as $i => $slot ) {
	list( $dept, $sub ) = $slot;
	$count_for_slot      = $per_slot + ( $i < $remainder ? 1 : 0 );
	$range               = isset( $price_ranges[ $sub ] ) ? $price_ranges[ $sub ] : array( 499, 1499 );
	list( $min_price, $max_price ) = $range;

	for ( $n = 1; $n <= $count_for_slot; $n++ ) {
		$style      = $style_words[ array_rand( $style_words ) ];
		$color_name = $colors[ array_rand( $colors ) ];
		$brand_name = $brands[ array_rand( $brands ) ];
		$name       = "$brand_name $style $sub";
		$sku        = strtoupper( substr( $dept, 0, 2 ) . substr( preg_replace( '/[^A-Za-z]/', '', $sub ), 0, 3 ) ) . '-' . str_pad( $created + 1, 5, '0', STR_PAD_LEFT );

		if ( wc_get_product_id_by_sku( $sku ) ) {
			$created++;
			$progress->tick();
			continue;
		}

		$regular_price = rand( $min_price, $max_price );
		$on_sale       = ( rand( 1, 100 ) <= 30 );
		$sale_price    = $on_sale ? (int) round( $regular_price * ( rand( 60, 85 ) / 100 ) ) : '';

		$is_variable = in_array( $sub, $variable_eligible, true ) && ( rand( 1, 100 ) <= ( $VARIABLE_RATIO * 100 ) );

		$stock_roll   = rand( 1, 100 );
		$stock_qty    = $stock_roll <= 10 ? 0 : ( $stock_roll <= 20 ? rand( 1, 4 ) : rand( 5, 200 ) );
		$stock_status = $stock_qty > 0 ? 'instock' : 'outofstock';

		$description       = "The $name is built for $style comfort, made from breathable fabric designed to hold up to daily wear. A versatile pick for your $dept wardrobe.";
		$short_description = "$style $sub from $brand_name.";

		$tag_keys = (array) array_rand( array_flip( $tag_pool ), rand( 1, 3 ) );
		$tag_ids  = array_map( 'get_or_create_tag_id', $tag_keys );

		$product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_sku( $sku );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_description( $description );
		$product->set_short_description( $short_description );
		$product->set_category_ids( array( $category_ids[ $dept ], $category_ids["$dept>$sub"] ) );
		$product->set_tag_ids( $tag_ids );

		$attrs = array();

		$size_attr = new WC_Product_Attribute();
		$size_attr->set_id( wc_attribute_taxonomy_id_by_name( 'size' ) );
		$size_attr->set_name( 'pa_size' );
		$size_attr->set_options( $is_variable ? array_values( $size_map ) : array( $size_map[ $sizes[ array_rand( $sizes ) ] ] ) );
		$size_attr->set_visible( true );
		$size_attr->set_variation( $is_variable );
		$attrs[] = $size_attr;

		$color_attr = new WC_Product_Attribute();
		$color_attr->set_id( wc_attribute_taxonomy_id_by_name( 'color' ) );
		$color_attr->set_name( 'pa_color' );
		$color_attr->set_options( $is_variable ? array_values( $color_map ) : array( $color_map[ $color_name ] ) );
		$color_attr->set_visible( true );
		$color_attr->set_variation( $is_variable );
		$attrs[] = $color_attr;

		$product->set_attributes( $attrs );

		if ( ! $is_variable ) {
			$product->set_regular_price( (string) $regular_price );
			if ( $on_sale ) {
				$product->set_sale_price( (string) $sale_price );
			}
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock_qty );
			$product->set_stock_status( $stock_status );
		}

		$product_id = $product->save();
		wp_set_object_terms( $product_id, array( $brand_map[ $brand_name ] ), 'product_brand' );

		if ( $is_variable ) {
			$var_sizes  = array_slice( $sizes, 0, rand( 3, 4 ) );
			$var_colors = array_slice( $colors, 0, rand( 2, 3 ) );
			foreach ( $var_sizes as $vsize ) {
				foreach ( $var_colors as $vcolor ) {
					$variation = new WC_Product_Variation();
					$variation->set_parent_id( $product_id );
					$variation->set_attributes(
						array(
							'pa_size'  => sanitize_title( $vsize ),
							'pa_color' => sanitize_title( $vcolor ),
						)
					);
					$vprice = max( 50, $regular_price + rand( -50, 50 ) );
					$variation->set_regular_price( (string) $vprice );
					if ( $on_sale ) {
						$variation->set_sale_price( (string) $sale_price );
					}
					$variation->set_manage_stock( true );
					$vstock = rand( 0, 100 );
					$variation->set_stock_quantity( $vstock );
					$variation->set_stock_status( $vstock > 0 ? 'instock' : 'outofstock' );
					$variation->set_sku( $sku . '-' . strtoupper( substr( $vsize, 0, 2 ) ) . strtoupper( substr( $vcolor, 0, 2 ) ) );
					$variation->save();
				}
			}
			WC_Product_Variable::sync( $product_id );
		}

		$created++;
		$progress->tick();

		if ( 0 === $created % 50 ) {
			wp_cache_flush();
		}
	}
}

$progress->finish();
WP_CLI::success( "Done. $created products created/verified." );