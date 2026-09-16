<?php
/**
 * Plugin Name: Simple API Content Sync
 * Description: Pulls content from an external API and saves it as WordPress posts.
 * Version: 1.0
 * Author: Atul Jindal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Simple_API_Content_Sync {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_run_api_sync', array( $this, 'run_sync' ) );
	}

	/**
	 * Add a simple page under Tools.
	 */
	public function add_menu() {
		add_management_page(
			'API Content Sync',
			'API Content Sync',
			'manage_options',
			'api-content-sync',
			array( $this, 'settings_page' )
		);
	}

	/**
	 * Save the API URL and token using the WordPress Settings API.
	 */
	public function register_settings() {
		register_setting(
			'api_content_sync_settings',
			'api_content_sync_url',
			array(
				'sanitize_callback' => 'esc_url_raw',
			)
		);

		register_setting(
			'api_content_sync_settings',
			'api_content_sync_token',
			array(
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
	}

	/**
	 * Small settings page so the API values do not need to be hard-coded.
	 */
	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>API Content Sync</h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'api_content_sync_settings' ); ?>

				<table class="form-table">
					<tr>
						<th>
							<label for="api_content_sync_url">API URL</label>
						</th>
						<td>
							<input
								type="url"
								id="api_content_sync_url"
								name="api_content_sync_url"
								class="regular-text"
								value="<?php echo esc_attr( get_option( 'api_content_sync_url' ) ); ?>"
							>
						</td>
					</tr>

					<tr>
						<th>
							<label for="api_content_sync_token">API Token</label>
						</th>
						<td>
							<input
								type="password"
								id="api_content_sync_token"
								name="api_content_sync_token"
								class="regular-text"
								value="<?php echo esc_attr( get_option( 'api_content_sync_token' ) ); ?>"
							>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save Settings' ); ?>
			</form>

			<hr>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="run_api_sync">

				<?php
				// Protect the manual sync action from CSRF.
				wp_nonce_field( 'run_api_sync' );
				?>

				<?php submit_button( 'Run Sync', 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Run the sync when an admin clicks the button.
	 */
	public function run_sync() {
		// Only admins should be able to run the import.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to do this.' );
		}

		check_admin_referer( 'run_api_sync' );

		$items = $this->get_api_data();

		if ( is_wp_error( $items ) ) {
			wp_die( esc_html( $items->get_error_message() ) );
		}

		foreach ( $items as $item ) {
			$this->save_post( $item );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'api-content-sync',
					'synced' => 1,
				),
				admin_url( 'tools.php' )
			)
		);

		exit;
	}

	/**
	 * Get content from the API.
	 */
	private function get_api_data() {
		// Cache the response so repeated syncs do not hit the API every time.
		$cached = get_transient( 'api_content_sync_data' );

		if ( false !== $cached ) {
			return $cached;
		}

		$url   = get_option( 'api_content_sync_url' );
		$token = get_option( 'api_content_sync_token' );

		if ( empty( $url ) ) {
			return new WP_Error( 'missing_url', 'Please enter an API URL first.' );
		}

		$headers = array(
			'Accept' => 'application/json',
		);

		if ( ! empty( $token ) ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $headers,
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			return new WP_Error(
				'api_error',
				'The API returned an unexpected response.'
			);
		}

		$data = json_decode(
			wp_remote_retrieve_body( $response ),
			true
		);

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'invalid_json',
				'The API response could not be read.'
			);
		}

		// Some APIs return an "items" key, others return the array directly.
		$items = isset( $data['items'] ) ? $data['items'] : $data;
		
		if ( ! is_array( $items ) ) {
			return new WP_Error(
				'invalid_data',
				'No content items were found.'
			);
		}
		
		set_transient(
			'api_content_sync_data',
			$items,
			10 * MINUTE_IN_SECONDS
		);

		return $items;
	}

	/**
	 * Create a new post or update the existing one.
	 */
	private function save_post( $item ) {
		// Keep the sample simple: ID and title are required.
		if ( empty( $item['id'] ) || empty( $item['title'] ) ) {
			return;
		}

		$external_id = sanitize_text_field( $item['id'] );
		$title       = sanitize_text_field( $item['title'] );
		$content     = wp_kses_post( $item['content'] ?? '' );

		$existing_post_id = $this->find_existing_post( $external_id );

		$post_data = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_type'    => 'post',
		);

		if ( $existing_post_id ) {
			// Update the same post instead of creating duplicates.
			$post_data['ID'] = $existing_post_id;

			$post_id = wp_update_post(
				wp_slash( $post_data ),
				true
			);
		} else {
			$post_id = wp_insert_post(
				wp_slash( $post_data ),
				true
			);
		}

		if ( ! is_wp_error( $post_id ) ) {
			update_post_meta(
				$post_id,
				'_api_content_sync_id',
				$external_id
			);
		}
	}

	/**
	 * Find a post that was already imported earlier.
	 */
	private function find_existing_post( $external_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_api_content_sync_id',
				'meta_value'     => $external_id,
			)
		);

		if ( empty( $query->posts ) ) {
			return 0;
		}

		return (int) $query->posts[0];
	}
}

new Simple_API_Content_Sync();
