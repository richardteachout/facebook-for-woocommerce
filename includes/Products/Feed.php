<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package FacebookCommerce
 */

namespace SkyVerge\WooCommerce\Facebook\Products;

defined( 'ABSPATH' ) or exit;

use SkyVerge\WooCommerce\PluginFramework\v5_5_4 as Framework;

/**
 * The main product feed handler.
 *
 * This will eventually replace \WC_Facebook_Product_Feed as we refactor and move its functionality here.
 *
 * @since 1.11.0-dev.1
 */
class Feed {


	/** @var string the action callback for generating a feed */
	const GENERATE_FEED_CALLBACK = 'wc_facebook_regenerate_feed';

	/** @var string the action slug for getting the product feed */
	const REQUEST_FEED_ACTION = 'wc_facebook_get_feed_data';


	/**
	 * Feed constructor.
	 *
	 * @since 1.11.0-dev.1
	 */
	public function __construct() {

		// add the necessary action and filter hooks
		$this->add_hooks();
	}


	/**
	 * Adds the necessary action and filter hooks.
	 *
	 * @since 1.11.0-dev.1
	 */
	private function add_hooks() {

		// handle the feed data request
		add_action( 'woocommerce_api_' . self::REQUEST_FEED_ACTION, [ $this, 'handle_feed_data_request' ] );
	}


	/**
	 * Handles the feed data request.
	 *
	 * @internal
	 *
	 * @since 1.11.0-dev.1
	 */
	public function handle_feed_data_request() {

		\WC_Facebookcommerce_Utils::log( 'Facebook is requesting the product feed.' );

		$feed_handler = new \WC_Facebook_Product_Feed();
		$file_path    = $feed_handler->get_file_path();

		// regenerate if the file doesn't exist
		if ( ! file_exists( $file_path ) ) {
			$feed_handler->generate_feed();
		}

		try {

			// bail early if the file can't be read
			if ( ! is_readable( $file_path ) ) {
				throw new Framework\SV_WC_Plugin_Exception( 'File is not readable.', 404 );
			}

			// set the download headers
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Description: File Transfer' );
			header( 'Content-Disposition: attachment; filename="' . basename( $file_path ) . '"' );
			header( 'Expires: 0' );
			header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
			header( 'Pragma: public' );
			header( 'Content-Length:'. filesize( $file_path ) );

			$file = @fopen( $file_path, 'rb' );

			if ( ! $file ) {
				throw new Framework\SV_WC_Plugin_Exception( 'Could not open feed file.', 500 );
			}

			// fpassthru might be disabled in some hosts (like Flywheel)
			if ( $this->is_fpassthru_disabled() || ! @fpassthru( $file ) ) {

				\WC_Facebookcommerce_Utils::log( 'fpassthru is disabled: getting file contents' );

				$contents = @stream_get_contents( $file );

				if ( ! $contents ) {
					throw new Framework\SV_WC_Plugin_Exception( 'Could not get feed file contents.', 500 );
				}

				echo $contents;
			}

		} catch ( \Exception $exception ) {

			\WC_Facebookcommerce_Utils::log( 'Could not serve product feed. ' . $exception->getMessage() . ' (' . $exception->getCode() . ')' );

			status_header( $exception->getCode() );
		}

		exit;
	}


	/**
	 * Checks whether fpassthru has been disabled in PHP.
	 *
	 * Helper method, do not open to public.
	 *
	 * @since 1.11.0-dev.1
	 *
	 * @return bool
	 */
	private function is_fpassthru_disabled() {

		$disabled = false;

		if ( function_exists( 'ini_get' ) ) {

			$disabled_functions = @ini_get( 'disable_functions' );

			$disabled = is_string( $disabled_functions ) && in_array( 'fpassthru', explode( ',', $disabled_functions ), false );
		}

		return $disabled;
	}


}
