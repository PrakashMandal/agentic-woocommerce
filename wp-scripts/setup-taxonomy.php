<?php
/**
 * Step 1: Categories + global attributes (Size, Color, Brand) + their terms.
 *
 * Run:
 *   docker compose run --rm wpcli wp eval-file setup-taxonomy.php
 *
 * Idempotent — safe to re-run. Check Products > Categories and
 * Products > Attributes in wp-admin afterwards before moving on to
 * generate-products.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run this via wp-cli only.\n";
	exit( 1 );
}

$category_tree = array(
	'Men'         => array( 'T-Shirts', 'Shirts', 'Jeans', 'Trousers', 'Jackets', 'Hoodies' ),
	'Women'       => array( 'Dresses', 'Tops', 'Jeans', 'Trousers', 'Jackets', 'Kurtis' ),
	'Kids'        => array( 'T-Shirts', 'Shirts', 'Dresses', 'Jeans' ),
	'Accessories' => array( 'Belts', 'Caps', 'Bags', 'Socks' ),
);

$sizes  = array( 'XS', 'S', 'M', 'L', 'XL', 'XXL' );
$colors = array( 'Black', 'White', 'Navy', 'Grey', 'Red', 'Olive', 'Beige', 'Maroon', 'Sky Blue', 'Mustard' );
$brands = array( 'Urbane Threads', 'Coastline Co.', 'Northgate Apparel', 'Pixel & Thread', 'Meridian Wear', 'Larkspur', 'Foundry Denim', 'Cobalt Row', 'Aster & Vine', 'Hemlock Supply' );

// ---------------------------------------------------------------------
function get_or_create_term_id( $name, $taxonomy, $parent = 0 ) {
	$existing = term_exists( $name, $taxonomy, $parent ?: null );
	if ( $existing ) {
		return (int) $existing['term_id'];
	}
	$result = wp_insert_term( $name, $taxonomy, array( 'parent' => $parent ) );
	if ( is_wp_error( $result ) ) {
		WP_CLI::warning( "Term error [$name / $taxonomy]: " . $result->get_error_message() );
		return 0;
	}
	return (int) $result['term_id'];
}

function ensure_global_attribute( $label, $slug ) {
	$attr_id = wc_attribute_taxonomy_id_by_name( $slug );
	if ( ! $attr_id ) {
		$attr_id = wc_create_attribute(
			array(
				'name'         => $label,
				'slug'         => $slug,
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			)
		);
		if ( is_wp_error( $attr_id ) ) {
			WP_CLI::error( "Attribute error [$label]: " . $attr_id->get_error_message() );
		}
		delete_transient( 'wc_attribute_taxonomies' );
	}

	// WC_Post_Types::register_taxonomies() early-returns once
	// taxonomy_exists('product_type') is true, so it's a no-op here.
	// Register pa_{slug} directly so it's usable in this same request.
	$taxonomy_name = wc_attribute_taxonomy_name( $slug );
	if ( ! taxonomy_exists( $taxonomy_name ) ) {
		register_taxonomy(
			$taxonomy_name,
			apply_filters( 'woocommerce_taxonomy_objects_' . $taxonomy_name, array( 'product' ) ),
			apply_filters(
				'woocommerce_taxonomy_args_' . $taxonomy_name,
				array(
					'hierarchical' => false,
					'show_ui'      => false,
					'query_var'    => true,
					'rewrite'      => false,
					'public'       => false,
					'label'        => $label,
				)
			)
		);
	}
	return $attr_id;
}

// ---------------------------------------------------------------------
// 1. Categories
// ---------------------------------------------------------------------
$cat_count = 0;
foreach ( $category_tree as $dept => $subs ) {
	$dept_id = get_or_create_term_id( $dept, 'product_cat' );
	$cat_count++;
	foreach ( $subs as $sub ) {
		get_or_create_term_id( $sub, 'product_cat', $dept_id );
		$cat_count++;
	}
}
WP_CLI::log( "Categories ready: $cat_count total (departments + subcategories)." );

// ---------------------------------------------------------------------
// 2. Size / Color: true variation attributes, created as custom pa_ attributes
// ---------------------------------------------------------------------
ensure_global_attribute( 'Size', 'size' );
ensure_global_attribute( 'Color', 'color' );

$size_created  = 0;
$color_created = 0;
foreach ( $sizes as $s ) {
	if ( get_or_create_term_id( $s, 'pa_size' ) ) {
		$size_created++;
	}
}
foreach ( $colors as $c ) {
	if ( get_or_create_term_id( $c, 'pa_color' ) ) {
		$color_created++;
	}
}
WP_CLI::log( "Attributes ready: $size_created sizes, $color_created colors." );

// ---------------------------------------------------------------------
// 3. Brand: WooCommerce's native taxonomy (Products > Brands since WC 9.6) —
// already registered by core on every request, created exactly like product_cat.
// ---------------------------------------------------------------------
$brand_created = 0;
foreach ( $brands as $b ) {
	if ( get_or_create_term_id( $b, 'product_brand' ) ) {
		$brand_created++;
	}
}

WP_CLI::success( "Done: $cat_count categories, $size_created sizes, $color_created colors, $brand_created brands. Check Products > Attributes and Products > Brands in wp-admin before running generate-products.php." );