<?php
/**
 * Step 3: Generate demo order history (500+ orders) against the 518
 * products from generate-products.php.
 *
 * Run:
 *   docker compose run --rm wpcli wp eval-file generate-orders.php
 *
 * NOT idempotent by design (orders have no natural unique key like SKU) —
 * every run adds more orders. Each one is tagged created_via
 * 'demo-generator' so you can find/wipe them later:
 *   wc_get_orders(['created_via' => 'demo-generator'])
 *
 * Note: this does NOT decrement product stock — stock levels stay as
 * set by generate-products.php so the low-stock demo data stays intact.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run this via wp-cli only.\n";
	exit( 1 );
}

// ---------------------------------------------------------------------
// CONFIG
// ---------------------------------------------------------------------
$TARGET_ORDERS   = 520;
$CUSTOMER_COUNT  = 60;
$DATE_RANGE_DAYS = 180;
$GUEST_RATIO     = 0.20; // fraction of orders with no linked customer account

// Real WooCommerce order statuses, weighted like a normal store.
$status_weights = array(
	'completed'  => 45,
	'processing' => 15,
	'pending'    => 5,
	'on-hold'    => 5,
	'cancelled'  => 8,
	'failed'     => 10,
	'refunded'   => 12,
);

$first_names = array( 'Aarav', 'Vivaan', 'Aditya', 'Ishaan', 'Kabir', 'Ananya', 'Diya', 'Saanvi', 'Myra', 'Priya', 'Rohan', 'Karan', 'Neha', 'Pooja', 'Arjun', 'Sanya', 'Kunal', 'Riya', 'Amit', 'Sneha' );
$last_names  = array( 'Sharma', 'Verma', 'Gupta', 'Singh', 'Kumar', 'Yadav', 'Mishra', 'Reddy', 'Nair', 'Rao', 'Das', 'Chopra', 'Malhotra', 'Joshi', 'Iyer' );
$cities      = array(
	array( 'city' => 'Patna', 'state' => 'BR' ),
	array( 'city' => 'Delhi', 'state' => 'DL' ),
	array( 'city' => 'Mumbai', 'state' => 'MH' ),
	array( 'city' => 'Bengaluru', 'state' => 'KA' ),
	array( 'city' => 'Hyderabad', 'state' => 'TG' ),
	array( 'city' => 'Pune', 'state' => 'MH' ),
	array( 'city' => 'Kolkata', 'state' => 'WB' ),
	array( 'city' => 'Lucknow', 'state' => 'UP' ),
);
$payment_methods = array(
	'cod'    => 'Cash on Delivery',
	'bacs'   => 'Direct Bank Transfer',
	'paypal' => 'PayPal',
	'stripe' => 'Credit Card (Stripe)',
);

// ---------------------------------------------------------------------
// 1. Demo customers (idempotent — matched by email)
// ---------------------------------------------------------------------
function get_or_create_demo_customer( $i, $first_names, $last_names ) {
	$email = "democustomer{$i}@example.test";
	$user  = get_user_by( 'email', $email );
	if ( $user ) {
		return $user->ID;
	}
	$first = $first_names[ array_rand( $first_names ) ];
	$last  = $last_names[ array_rand( $last_names ) ];
	$user_id = wp_insert_user(
		array(
			'user_login' => "democustomer{$i}",
			'user_email' => $email,
			'user_pass'  => wp_generate_password(),
			'first_name' => $first,
			'last_name'  => $last,
			'role'       => 'customer',
		)
	);
	return is_wp_error( $user_id ) ? 0 : $user_id;
}

$customer_ids = array();
for ( $i = 1; $i <= $CUSTOMER_COUNT; $i++ ) {
	$customer_ids[] = get_or_create_demo_customer( $i, $first_names, $last_names );
}
WP_CLI::log( 'Demo customers ready: ' . count( $customer_ids ) );

// ---------------------------------------------------------------------
// 2. Sellable pool: every published product, variations expanded
// ---------------------------------------------------------------------
$product_ids = get_posts(
	array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

$sellable = array();
foreach ( $product_ids as $pid ) {
	$p = wc_get_product( $pid );
	if ( ! $p ) {
		continue;
	}
	if ( $p->is_type( 'variable' ) ) {
		foreach ( $p->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v ) {
				$sellable[] = $v;
			}
		}
	} else {
		$sellable[] = $p;
	}
}

if ( empty( $sellable ) ) {
	WP_CLI::error( 'No published products found. Run generate-products.php first.' );
}
WP_CLI::log( 'Sellable items (products + variations): ' . count( $sellable ) );

// ---------------------------------------------------------------------
// 3. Weighted status picker
// ---------------------------------------------------------------------
function pick_weighted_status( $weights ) {
	$roll = rand( 1, array_sum( $weights ) );
	foreach ( $weights as $status => $w ) {
		if ( $roll <= $w ) {
			return $status;
		}
		$roll -= $w;
	}
	return 'completed';
}

// ---------------------------------------------------------------------
// 4. Generate orders
// ---------------------------------------------------------------------
$created  = 0;
$progress = \WP_CLI\Utils\make_progress_bar( 'Creating orders', $TARGET_ORDERS );

for ( $n = 1; $n <= $TARGET_ORDERS; $n++ ) {
	$is_guest = ( rand( 1, 100 ) <= ( $GUEST_RATIO * 100 ) );
	$location = $cities[ array_rand( $cities ) ];
	$first    = $first_names[ array_rand( $first_names ) ];
	$last     = $last_names[ array_rand( $last_names ) ];
	$email    = strtolower( $first . '.' . $last . rand( 1, 999 ) . '@example.test' );

	$address = array(
		'first_name' => $first,
		'last_name'  => $last,
		'email'      => $email,
		'phone'      => '9' . rand( 100000000, 999999999 ),
		'address_1'  => rand( 1, 999 ) . ' MG Road',
		'city'       => $location['city'],
		'state'      => $location['state'],
		'postcode'   => (string) rand( 100000, 999999 ),
		'country'    => 'IN',
	);

	$order = wc_create_order();
	$order->set_created_via( 'demo-generator' );

	if ( ! $is_guest ) {
		$customer_id = $customer_ids[ array_rand( $customer_ids ) ];
		$order->set_customer_id( $customer_id );
	}
	$order->set_address( $address, 'billing' );
	$order->set_address( $address, 'shipping' );

	$line_count = rand( 1, 5 );
	for ( $l = 0; $l < $line_count; $l++ ) {
		$item = $sellable[ array_rand( $sellable ) ];
		$order->add_product( $item, rand( 1, 3 ) );
	}

	$method_key = array_rand( $payment_methods );
	$order->set_payment_method( $method_key );
	$order->set_payment_method_title( $payment_methods[ $method_key ] );

	$timestamp = time() - rand( 0, $DATE_RANGE_DAYS * DAY_IN_SECONDS ) - rand( 0, DAY_IN_SECONDS - 1 );
	$order->set_date_created( $timestamp );

	$order->calculate_totals();

	$status = pick_weighted_status( $status_weights );

	if ( 'refunded' === $status ) {
		// Give it a real completed life first, then a real refund record —
		// wc_create_refund() flips the parent order to 'refunded' itself.
		$order->set_status( 'completed' );
		$order->save();
		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => $order->get_total(),
				'reason'   => 'Demo refund',
			)
		);
	} else {
		$order->set_status( $status ); // set_status(), not update_status() — skips customer emails/hooks
		$order->save();
	}

	$created++;
	$progress->tick();

	if ( 0 === $created % 50 ) {
		wp_cache_flush();
	}
}

$progress->finish();
WP_CLI::success( "Done. $created orders created." );