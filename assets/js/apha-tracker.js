/**
 * Advanced PostHog Analytics Frontend Event Tracker.
 *
 * Captures e-commerce events (Product Viewed, Cart Viewed, Checkout Started,
 * Product Clicked, Coupon Applied/Removed, etc.) and sends them to PostHog
 * via the JS SDK.
 *
 * @package AdvancedPostHogAnalytics
 */
(function () {
	'use strict';

	/**
	 * Maximum number of attempts to wait for dependencies before giving up.
	 *
	 * @type {number}
	 */
	var MAX_POLL_ATTEMPTS = 50;

	/**
	 * Interval in milliseconds between dependency checks.
	 *
	 * @type {number}
	 */
	var POLL_INTERVAL_MS = 100;

	/**
	 * Safely capture a PostHog event.
	 *
	 * @param {string} eventName  The event name.
	 * @param {Object} properties The event properties.
	 */
	function capture(eventName, properties) {
		if (window.posthog && typeof window.posthog.capture === 'function') {
			window.posthog.capture(eventName, properties || {});
		}
	}

	/**
	 * Read a cookie value by name.
	 *
	 * @param {string} name Cookie name.
	 * @return {string|null} Cookie value or null.
	 */
	function getCookie(name) {
		var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
		return match ? decodeURIComponent(match[2]) : null;
	}

	/**
	 * Get the WooCommerce cart hash from the cookie, or generate a fallback.
	 *
	 * @return {string} Cart identifier.
	 */
	function getCartId() {
		return getCookie('woocommerce_cart_hash') || getCookie('wp_woocommerce_session') || 'unknown';
	}

	/**
	 * Build a human-readable variant label from a variation's attributes.
	 *
	 * @param {Object} attributes Variation attributes (e.g. {attribute_pa_color: "blue"}).
	 * @return {string} Human-readable label like "blue, large".
	 */
	function buildVariantLabel(attributes) {
		if (!attributes) {
			return '';
		}
		var parts = [];
		for (var key in attributes) {
			if (attributes.hasOwnProperty(key) && attributes[key]) {
				parts.push(attributes[key]);
			}
		}
		return parts.join(', ');
	}

	/**
	 * Extract product info from an add-to-cart button on archive pages.
	 *
	 * @param {jQuery} $button The add-to-cart button jQuery element.
	 * @return {Object} Product properties extracted from DOM.
	 */
	function getProductInfoFromButton($button) {
		var props = {};
		var productId = $button.data('product_id') || $button.val();

		if (productId) {
			props.product_id = parseInt(productId, 10);
		}

		props.quantity = parseInt($button.data('quantity'), 10) || 1;

		// Try to find the product wrapper and extract available data.
		var $productEl = $button.closest('.product');

		if ($productEl.length) {
			var $title = $productEl.find('.woocommerce-loop-product__title, .product_title');
			if ($title.length) {
				props.name = $title.text().trim();
			}

			var $price = $productEl.find('.price ins .woocommerce-Price-amount, .price > .woocommerce-Price-amount');
			if ($price.length) {
				var priceText = $price.first().text().replace(/[^0-9.,]/g, '').replace(',', '.');
				var parsedPrice = parseFloat(priceText);
				if (!isNaN(parsedPrice)) {
					props.price = parsedPrice;
				}
			}
		}

		return props;
	}

	/**
	 * Initialize event tracking once dependencies are available.
	 *
	 * @param {Object} dataLayer The aphaDataLayer object.
	 */
	function initTracking(dataLayer) {
		var pageType = dataLayer.page_type;

		// --- Product Viewed ---
		if (pageType === 'product' && dataLayer.product) {
			capture('Product Viewed', dataLayer.product);

			// Listen for variation selection on variable product pages.
			if (dataLayer.variations && window.jQuery) {
				jQuery('.variations_form').on('found_variation', function (event, variation) {
					var variationProps = {};
					var i, len, v;

					// Copy base product properties.
					for (var key in dataLayer.product) {
						if (dataLayer.product.hasOwnProperty(key)) {
							variationProps[key] = dataLayer.product[key];
						}
					}

					// Find matching variation in our data layer for enrichment.
					if (dataLayer.variations) {
						for (i = 0, len = dataLayer.variations.length; i < len; i++) {
							v = dataLayer.variations[i];
							if (v.variation_id === variation.variation_id) {
								// Use human-readable variant label instead of numeric ID.
								variationProps.variant = v.variant || buildVariantLabel(v.attributes);
								variationProps.variation_id = v.variation_id;
								variationProps.price = v.price;
								variationProps.sku = v.sku;
								if (v.image_url) {
									variationProps.image_url = v.image_url;
								}
								break;
							}
						}
					}

					// Fallback: build label from variation event data.
					if (!variationProps.variant && variation.attributes) {
						variationProps.variant = buildVariantLabel(variation.attributes);
					}
					if (!variationProps.variation_id) {
						variationProps.variation_id = variation.variation_id;
					}
					if (variation.display_price !== undefined) {
						variationProps.price = variation.display_price;
					}
					if (variation.sku) {
						variationProps.sku = variation.sku;
					}
					if (variation.image && variation.image.url) {
						variationProps.image_url = variation.image.url;
					}

					capture('Product Viewed', variationProps);
				});
			}
		}

		// --- Product List Viewed ---
		if (pageType === 'product_list') {
			capture('Product List Viewed', {
				name: dataLayer.list_name,
				type: dataLayer.list_type,
				products: dataLayer.products || []
			});
		}

		// --- Product Clicked (on product list pages) ---
		if (pageType === 'product_list' && dataLayer.products) {
			var productLinks = document.querySelectorAll('.products .product a.woocommerce-LoopProduct-link, .products .product .woocommerce-loop-product__link');

			productLinks.forEach(function (link) {
				link.addEventListener('click', function () {
					var productEl = link.closest('.product');
					if (!productEl) {
						return;
					}

					var props = {
						list_name: dataLayer.list_name,
						list_type: dataLayer.list_type
					};

					// Try to find product data from the data layer by matching position.
					var allProducts = productEl.parentElement ? productEl.parentElement.querySelectorAll('.product') : [];
					var position = Array.prototype.indexOf.call(allProducts, productEl);

					if (position >= 0 && dataLayer.products[position]) {
						var p = dataLayer.products[position];
						props.product_id = p.product_id;
						props.name = p.name;
						props.price = p.price;
						props.sku = p.sku;
						props.category = p.category;
						props.position = position + 1;
					}

					capture('Product Clicked', props);
				});
			});
		}

		// --- Products Searched ---
		if (pageType === 'search') {
			capture('Products Searched', {
				query: dataLayer.query
			});
		}

		// --- Cart Viewed ---
		if (pageType === 'cart') {
			capture('Cart Viewed', {
				products: dataLayer.products || [],
				cart_total: dataLayer.cart_total,
				currency: dataLayer.currency
			});
		}

		// --- Checkout Started ---
		if (pageType === 'checkout') {
			capture('Checkout Started', {
				products: dataLayer.products || [],
				total: dataLayer.total,
				subtotal: dataLayer.subtotal,
				shipping: dataLayer.shipping,
				tax: dataLayer.tax,
				coupon: dataLayer.coupon,
				currency: dataLayer.currency
			});
		}

		// --- Product Added (Classic WooCommerce) ---
		if (window.jQuery) {
			jQuery(document.body).on('added_to_cart', function (e, fragments, cartHash, $button) {
				var props = {
					cart_id: getCartId()
				};

				// On single product pages, use the data layer for product info.
				if (pageType === 'product' && dataLayer.product) {
					props.product_id = dataLayer.product.product_id;
					props.sku = dataLayer.product.sku;
					props.name = dataLayer.product.name;
					props.price = dataLayer.product.price;

					// Get quantity from the quantity input on the page.
					var $qty = jQuery('input[name="quantity"]');
					props.quantity = $qty.length ? parseInt($qty.val(), 10) || 1 : 1;
				} else if ($button && $button.length) {
					// On archive pages, extract from the button's data attributes.
					var buttonProps = getProductInfoFromButton($button);
					for (var key in buttonProps) {
						if (buttonProps.hasOwnProperty(key)) {
							props[key] = buttonProps[key];
						}
					}
				}

				capture('Product Added', props);
			});

			// --- Coupon Applied ---
			jQuery(document.body).on('applied_coupon', function (e, couponCode) {
				capture('Coupon Applied', {
					coupon_code: couponCode || '',
					cart_id: getCartId()
				});
			});

			// --- Coupon Removed ---
			jQuery(document.body).on('removed_coupon', function (e, couponCode) {
				capture('Coupon Removed', {
					coupon_code: couponCode || '',
					cart_id: getCartId()
				});
			});
		}

		// --- Product Added (Block-based Cart) ---
		document.body.addEventListener('wc-blocks_added_to_cart', function (e) {
			var props = {
				cart_id: getCartId()
			};

			if (e.detail) {
				if (e.detail.productId) {
					props.product_id = e.detail.productId;
				}
				if (e.detail.quantity) {
					props.quantity = e.detail.quantity;
				}
			}

			// Enrich from data layer on single product pages.
			if (pageType === 'product' && dataLayer.product) {
				props.name = dataLayer.product.name;
				props.sku = dataLayer.product.sku;
				props.price = dataLayer.product.price;
			}

			capture('Product Added', props);
		});

		// --- Product Removed (Classic WooCommerce) ---
		if (window.jQuery) {
			jQuery(document.body).on('removed_from_cart', function (e, fragments, cartHash, $button) {
				var props = {};

				if ($button && $button.length) {
					var productId = $button.data('product_id');
					if (productId) {
						props.product_id = parseInt(productId, 10);
					}

					var productName = $button.data('product_name');
					if (productName) {
						props.name = productName;
					}
				}

				props.cart_id = getCartId();
				capture('Product Removed', props);
			});
		}

		// --- Product Removed (Block-based Cart) ---
		document.body.addEventListener('wc-blocks_removed_from_cart', function (e) {
			var props = {
				cart_id: getCartId()
			};

			if (e.detail) {
				if (e.detail.productId) {
					props.product_id = e.detail.productId;
				}
				if (e.detail.quantity) {
					props.quantity = e.detail.quantity;
				}
			}

			capture('Product Removed', props);
		});

		// --- Payment Info Entered (checkout pages) ---
		if (pageType === 'checkout') {
			var paymentTracked = false;

			// Classic checkout: detect payment method radio change.
			if (window.jQuery) {
				jQuery(document.body).on('change', 'input[name="payment_method"]', function () {
					if (!paymentTracked) {
						paymentTracked = true;
						capture('Payment Info Entered', {
							payment_method: jQuery('input[name="payment_method"]:checked').val() || ''
						});
					}
				});
			}

			// Block checkout: detect interaction with payment area.
			var paymentArea = document.querySelector('.wc-block-components-payment-method-icons, .wc-block-checkout__payment-method');
			if (paymentArea) {
				paymentArea.addEventListener('click', function () {
					if (!paymentTracked) {
						paymentTracked = true;
						capture('Payment Info Entered', {});
					}
				});
			}
		}
	}

	/**
	 * Poll for required dependencies and start tracking when ready.
	 */
	function waitForDependencies() {
		var attempts = 0;

		var interval = setInterval(function () {
			attempts++;

			if (window.aphaDataLayer && window.posthog) {
				clearInterval(interval);
				initTracking(window.aphaDataLayer);
				return;
			}

			if (attempts >= MAX_POLL_ATTEMPTS) {
				clearInterval(interval);
			}
		}, POLL_INTERVAL_MS);
	}

	// Start polling for dependencies.
	if (window.aphaDataLayer && window.posthog) {
		initTracking(window.aphaDataLayer);
	} else {
		waitForDependencies();
	}
})();
