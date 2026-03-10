<?php
/**
 * Advanced PostHog Analytics Server-Side Event Tracking.
 *
 * Captures order lifecycle events (completed, refunded, status changes)
 * via the PostHog API on the server side for reliable, tamper-proof tracking.
 *
 * @package AdvancedPostHogAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class APHA_Server_Events
 *
 * Hooks into WooCommerce order actions to fire PostHog events
 * server-side with deduplication and identity resolution.
 */
class APHA_Server_Events {

	/**
	 * PostHog API instance.
	 *
	 * @var APHA_PostHog_API
	 */
	private $api;

	/**
	 * Identity manager instance.
	 *
	 * @var APHA_Identity
	 */
	private $identity;

	/**
	 * Product data helper instance.
	 *
	 * @var APHA_Product_Data
	 */
	private $product_data;

	/**
	 * Attribution engine instance (optional).
	 *
	 * @var APHA_Attribution|null
	 */
	private $attribution = null;

	/**
	 * In-memory guard to prevent duplicate Order Completed tracking
	 * when both woocommerce_payment_complete and woocommerce_order_status_completed
	 * fire in the same request (TOCTOU race condition).
	 *
	 * @var array
	 */
	private static $tracked_orders = array();

	/**
	 * Constructor.
	 *
	 * @param APHA_PostHog_API  $api          PostHog API instance.
	 * @param APHA_Identity     $identity     Identity manager instance.
	 * @param APHA_Product_Data $product_data Product data helper instance.
	 */
	public function __construct( APHA_PostHog_API $api, APHA_Identity $identity, APHA_Product_Data $product_data ) {
		$this->api          = $api;
		$this->identity     = $identity;
		$this->product_data = $product_data;

		// Primary hook for payment-based order completion.
		add_action( 'woocommerce_payment_complete', array( $this, 'track_order_completed' ), 10, 1 );

		// Backup hook for COD, BACS, manual completions.
		add_action( 'woocommerce_order_status_completed', array( $this, 'track_order_completed' ), 10, 1 );

		// Refund hooks.
		add_action( 'woocommerce_order_fully_refunded', array( $this, 'track_order_refunded' ), 10, 2 );
		add_action( 'woocommerce_order_partially_refunded', array( $this, 'track_order_refunded' ), 10, 2 );

		// Status change tracking.
		add_action( 'woocommerce_order_status_changed', array( $this, 'track_order_status_changed' ), 10, 3 );
	}

	/**
	 * Set the attribution engine instance.
	 *
	 * @param APHA_Attribution $attribution Attribution engine.
	 * @return void
	 */
	public function set_attribution( APHA_Attribution $attribution ) {
		$this->attribution = $attribution;
	}

	/**
	 * Track an Order Completed event in PostHog.
	 *
	 * Uses both an in-memory static guard (prevents TOCTOU race when
	 * multiple hooks fire in the same request) and order meta for
	 * cross-request deduplication.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function track_order_completed( $order_id ) {
		// In-memory guard: prevent duplicate tracking in the same request.
		if ( isset( self::$tracked_orders[ $order_id ] ) ) {
			return;
		}
		self::$tracked_orders[ $order_id ] = true;

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		// Cross-request deduplication via order meta.
		$already_tracked = $order->get_meta( '_apha_tracked' );

		if ( ! empty( $already_tracked ) ) {
			return;
		}

		// Identify first so the person merge resolves before the event is captured.
		$this->identity->identify_from_order( $order );

		// Use email as distinct_id when available so the event is attributed directly.
		$email = $order->get_billing_email();
		$distinct_id = ! empty( $email ) ? $email : $this->identity->get_distinct_id_for_order( $order );

		$properties = $this->product_data->get_order_properties( $order );

		// Merge attribution data into event properties.
		if ( $this->attribution ) {
			$attribution_data = $this->attribution->get_order_attribution( $order );
			$properties       = array_merge( $properties, $attribution_data );
		}

		// Blocking call to ensure the event is delivered before we mark it as tracked.
		$success = $this->api->capture( $distinct_id, 'Order Completed', $properties, true );

		if ( $success ) {
			$order->update_meta_data( '_apha_tracked', current_time( 'mysql' ) );
			$order->save();
		}
	}

	/**
	 * Track an Order Refunded event in PostHog.
	 *
	 * Uses per-refund deduplication meta to prevent duplicate refund events.
	 *
	 * @param int $order_id  WooCommerce order ID.
	 * @param int $refund_id WooCommerce refund ID.
	 * @return void
	 */
	public function track_order_refunded( $order_id, $refund_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		// Refund deduplication.
		$refund_meta_key = '_apha_refund_' . $refund_id . '_tracked';

		if ( ! empty( $order->get_meta( $refund_meta_key ) ) ) {
			return;
		}

		$refund = wc_get_order( $refund_id );

		if ( ! $refund ) {
			return;
		}

		$distinct_id = $this->identity->get_distinct_id_for_order( $order );

		$properties = array(
			'order_id'  => $order->get_id(),
			'refund_id' => $refund_id,
			'total'     => abs( (float) $refund->get_total() ),
			'currency'  => $order->get_currency(),
		);

		// Merge attribution data into refund events too.
		if ( $this->attribution ) {
			$attribution_data = $this->attribution->get_order_attribution( $order );
			$properties       = array_merge( $properties, $attribution_data );
		}

		// Include refunded line items if this is a partial refund.
		$refund_items = $refund->get_items();

		if ( ! empty( $refund_items ) ) {
			$products = array();

			foreach ( $refund_items as $refund_item ) {
				if ( ! $refund_item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$product  = $refund_item->get_product();
				$quantity = abs( $refund_item->get_quantity() );

				$unit_price = $quantity > 0
					? abs( (float) $refund_item->get_subtotal() ) / $quantity
					: 0.0;

				$product_entry = array(
					'product_id' => $refund_item->get_variation_id()
						? $refund_item->get_variation_id()
						: $refund_item->get_product_id(),
					'sku'        => $product ? $product->get_sku() : '',
					'name'       => $refund_item->get_name(),
					'price'      => $unit_price,
					'quantity'   => $quantity,
				);

				$products[] = $product_entry;
			}

			if ( ! empty( $products ) ) {
				$properties['products'] = $products;
			}
		}

		$this->api->capture( $distinct_id, 'Order Refunded', $properties );

		// Mark this refund as tracked.
		$order->update_meta_data( $refund_meta_key, current_time( 'mysql' ) );
		$order->save();
	}

	/**
	 * Track an Order Status Changed event in PostHog.
	 *
	 * @param int    $order_id   WooCommerce order ID.
	 * @param string $old_status Previous order status slug.
	 * @param string $new_status New order status slug.
	 * @return void
	 */
	public function track_order_status_changed( $order_id, $old_status, $new_status ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$distinct_id = $this->identity->get_distinct_id_for_order( $order );

		$properties = array(
			'order_id'        => $order->get_id(),
			'previous_status' => $old_status,
			'new_status'      => $new_status,
		);

		$this->api->capture( $distinct_id, 'Order Status Changed', $properties );
	}
}
