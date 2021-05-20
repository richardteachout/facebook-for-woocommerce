<?php

namespace SkyVerge\WooCommerce\Facebook\Jobs;

use Automattic\WooCommerce\ActionSchedulerJobFramework\Proxies\ActionSchedulerInterface;
use Automattic\WooCommerce\ActionSchedulerJobFramework\Utilities\BatchQueryOffset;
use Exception;
use SkyVerge\WooCommerce\Facebook\Feed\FeedDataExporter;
use SkyVerge\WooCommerce\Facebook\Feed\FeedFileHandler;
use WC_Facebookcommerce;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Class GenerateProductFeed
 *
 * @since 2.5.0
 */
class GenerateProductFeed extends AbstractChainedJob {

	use BatchQueryOffset, LoggingTrait;

	/**
	 * Array of processing results, used as a temporary storage for batch processing.
	 * Each entry is an array of feed fields.
	 * This is used when the batch processing is completed to export data to CSV file.
	 *
	 * @var array $processed_items.
	 */
	protected $processed_items = array();

	/**
	 * Feed file creation and manipulation utility.
	 *
	 * @var FeedFileHandler $feed_file_handler.
	 */
	protected $feed_file_handler;

	/**
	 * Exports data from WC_Product to format recognized by the feed.
	 *
	 * @var FeedDataExporter $feed_data_exporter.
	 */
	protected $feed_data_exporter;

	/**
	 * Constructor.
	 *
	 * @param ActionSchedulerInterface $action_scheduler   Action Scheduler facade.
	 * @param FeedFileHandler          $feed_file_handler  Feed file creation and manipulation handler.
	 * @param FeedDataExporter         $feed_data_exporter Handling of file data.
	 */
	public function __construct( ActionSchedulerInterface $action_scheduler, $feed_file_handler, $feed_data_exporter ) {
		parent::__construct( $action_scheduler );
		$this->feed_file_handler  = $feed_file_handler;
		$this->feed_data_exporter = $feed_data_exporter;
	}

	/**
	 * Called before starting the job.
	 */
	protected function handle_start() {
		$this->feed_file_handler->prepare_feed_folder();
		$this->feed_file_handler->create_fresh_feed_temporary_file();
		$this->feed_file_handler->write_to_feed_temporary_file(
			$this->feed_data_exporter->generate_header()
		);
	}

	/**
	 * Called after the finishing the job.
	 */
	protected function handle_end() {
		$this->feed_file_handler->replace_feed_file_with_temp_file();
	}

	/**
	 * Handle processing a chain batch.
	 *
	 * @hooked facebook_for_woocommerce/jobs/generate_feed/chain_batch
	 *
	 * @param int   $batch_number The batch number for the new batch.
	 * @param array $args         The args for the job.
	 *
	 * @throws Exception On error. The failure will be logged by Action Scheduler and the job chain will stop.
	 */
	public function handle_batch_action( int $batch_number, array $args ) {
		// Clear the processed_items in case we are processing more than one batch in a single Action Scheduler tick.
		$this->processed_items = array();
		parent::handle_batch_action( $batch_number, $args );
		$this->write_processed_items_to_feed();
	}

	/**
	 * After processing send items to the feed file.
	 */
	public function write_processed_items_to_feed() {
		if ( empty( $this->processed_items ) ) {
			return;
		}
		$this->feed_file_handler->write_to_feed_temporary_file(
			$this->feed_data_exporter->format_items_for_feed( $this->processed_items )
		);
	}

	/**
	 * Get a set of items for the batch.
	 *
	 * NOTE: when using an OFFSET based query to retrieve items it's recommended to order by the item ID while
	 * ASCENDING. This is so that any newly added items will not disrupt the query offset.
	 *
	 * @param int   $batch_number The batch number increments for each new batch in the job cycle.
	 * @param array $args         The args for the job.
	 *
	 * @throws Exception On error. The failure will be logged by Action Scheduler and the job chain will stop.
	 */
	protected function get_items_for_batch( int $batch_number, array $args ): array {
		global $wpdb;

		$product_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post.ID
				FROM wp_posts as post
				LEFT JOIN wp_posts as parent ON post.post_parent = parent.ID
				WHERE
					( post.post_type = 'product_variation' AND parent.post_status = 'publish' )
				OR
					( post.post_type = 'product' AND post.post_status = 'publish' )
				ORDER BY post.ID ASC
				LIMIT %d OFFSET %d",
				$this->get_batch_size(),
				$this->get_query_offset( $batch_number )
			)
		);

		return array_map( 'intval', $product_ids );
	}

	/**
	 * Filter-like function that runs before items in a batch are processed.
	 *
	 * For example, this could be useful for pre-fetching full objects.
	 *
	 * @param array $items
	 *
	 * @return array
	 */
	protected function filter_items_before_processing( array $items ): array {
		// Pre-fetch full product objects.
		// Variable products will be filtered out here since we don't need them for the feed. It's important to not
		// filter out variable products in ::get_items_for_batch() because if a batch only contains variable products
		// the job will end prematurely thinking it has nothing more to process.
		return wc_get_products(
			array(
				'type'    => array( 'simple', 'variation' ),
				'include' => $items,
				'orderby' => 'none',
			)
		);
	}

	/**
	 * Process a single item.
	 *
	 * @param WC_Product $product A single item from the get_items_for_batch() method.
	 * @param array      $args The args for the job.
	 *
	 * @throws Exception On error. The failure will be logged by Action Scheduler and the job chain will stop.
	 */
	protected function process_item( $product, array $args ) {
		try {
			if ( ! $product ) {
				throw new Exception( 'Product not found.' );
			}
			$this->processed_items[] = $this->feed_data_exporter->generate_row_data( $product );

		} catch ( Exception $e ) {
			$this->log(
				sprintf(
					'Error processing item #%d - %s',
					$product instanceof WC_Product ? $product->get_id() : 0,
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Get the name/slug of the job.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'generate_feed';
	}

	/**
	 * Get the name/slug of the plugin that owns the job.
	 *
	 * @return string
	 */
	public function get_plugin_name(): string {
		return WC_Facebookcommerce::PLUGIN_ID;
	}

	/**
	 * Get the job's batch size.
	 *
	 * @return int
	 */
	protected function get_batch_size(): int {
		return 15;
	}

}
