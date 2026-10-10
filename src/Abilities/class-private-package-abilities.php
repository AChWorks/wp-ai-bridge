<?php
/**
 * WordPress-browser ZIP ingress and MCP review/explicit-install Abilities.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Auth\Approved_OAuth_Clients;
use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Private_Package_Store;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/**
 * A native WordPress admin upload is the bytes transport, not an install shortcut.
 */
final class Private_Package_Abilities {
	const PAGE_SLUG    = 'wp-ai-bridge-private-zips';
	const POST_ACTION  = 'wpai_private_zip_upload';
	const NONCE_ACTION = 'wpai_private_zip_upload_nonce';
	const CRON_HOOK    = 'wpai_private_zip_cleanup';

	/** @var Private_Package_Store */
	private $store;

	public function __construct( Permissions $permissions ) {
		$this->store = new Private_Package_Store( $permissions );
	}

	/** Register only admin session and bounded cleanup hooks; no generic REST upload bearer scope. */
	public function boot() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 21 );
		add_action( 'admin_post_' . self::POST_ACTION, array( $this, 'handle_browser_upload' ) );
		add_action( 'init', array( $this, 'schedule_cleanup' ), 20 );
		add_action( self::CRON_HOOK, array( $this->store, 'cleanup_expired' ) );
	}

	public function schedule_cleanup() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public function register_menu() {
		add_submenu_page(
			'wp-ai-bridge',
			__( 'Private ZIP Packages', 'wp-ai-bridge' ),
			__( 'Private ZIP Packages', 'wp-ai-bridge' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_upload_page' )
		);
	}

	/** @return array<int,object> Two bounded, client-bound WordPress Abilities. */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/private-packages-read',
			array(
				'label'               => __( 'Inspect Private ZIP Packages', 'wp-ai-bridge' ),
				'description'         => __( 'Lists or inspects only approved, privately staged WordPress plugin/theme ZIPs bound to this user, blog and OAuth client. No package bytes or paths are returned.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'action'      => array(
							'type' => 'string',
							'enum' => array( 'list', 'get' ),
						),
						'artifact_id' => array(
							'type'    => 'string',
							'pattern' => '^[0-9a-f]{48}$',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'items' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'item'  => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->meta( true ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-ai-bridge/private-package-install',
			array(
				'label'               => __( 'Install Reviewed Private ZIP', 'wp-ai-bridge' ),
				'description'         => __( 'Explicitly installs the exact reviewed, hash-bound private ZIP through native WordPress Upgrader; never activates it. Unknown Core outcomes require recovery, never automatic retry.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'artifact_id' => array(
							'type'    => 'string',
							'pattern' => '^[0-9a-f]{48}$',
						),
						'sha256'      => array(
							'type'    => 'string',
							'pattern' => '^[0-9a-f]{64}$',
						),
						'kind'        => array(
							'type' => 'string',
							'enum' => array( 'plugin', 'theme' ),
						),
					),
					'required'             => array( 'artifact_id', 'sha256', 'kind' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'artifact_id' => array( 'type' => 'string' ),
						'kind'        => array( 'type' => 'string' ),
						'target'      => array( 'type' => 'string' ),
						'installed'   => array( 'type' => 'boolean' ),
						'activated'   => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'artifact_id', 'kind', 'target', 'installed', 'activated' ),
				),
				'execute_callback'    => array( $this, 'install' ),
				'permission_callback' => array( $this, 'can_install' ),
				'meta'                => $this->meta( false ),
			)
		);
		return array_values( array_filter( $registered, 'is_object' ) );
	}

	private function meta( $is_readonly ) {
		return array(
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations' => array(
				'readonly'    => $is_readonly,
				'destructive' => ! $is_readonly,
				'idempotent'  => $is_readonly,
			),
		);
	}

	/** A real validated OAuth/MCP client context is mandatory for an AI request. */
	public function can_read() {
		$client = OAuth_Server::authenticated_mcp_client_id();
		return '' !== $client && ( new Approved_OAuth_Clients() )->is_approved( $client ) &&
			( $this->store->allowed( 'plugin' ) || $this->store->allowed( 'theme' ) );
	}

	public function can_install( $input ) {
		return is_array( $input ) && isset( $input['kind'] ) &&
			$this->can_read() && $this->store->allowed( $input['kind'] );
	}

	/** @return array<string,mixed>|WP_Error */
	public function read( $input ) {
		if ( ! $this->can_read() ) {
			return new WP_Error( 'private_package_permission_denied', __( 'Private package authorization is not available.', 'wp-ai-bridge' ) );
		}
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'action', 'artifact_id' ) ) ) {
			return new WP_Error( 'private_package_invalid_request', __( 'The private package request is invalid.', 'wp-ai-bridge' ) );
		}
		$client = OAuth_Server::authenticated_mcp_client_id();
		$action = is_array( $input ) && isset( $input['action'] ) ? (string) $input['action'] : 'list';
		if ( 'list' === $action ) {
			if ( isset( $input['artifact_id'] ) ) {
				return new WP_Error( 'private_package_invalid_request', __( 'The private package request is invalid.', 'wp-ai-bridge' ) );
			}
			return array( 'items' => $this->store->list_for_client( $client ) );
		}
		if ( 'get' === $action && isset( $input['artifact_id'] ) ) {
			$entry = $this->store->inspect( $input['artifact_id'], $client );
			return is_wp_error( $entry ) ? $entry : array( 'item' => $entry );
		}
		return new WP_Error( 'private_package_invalid_request', __( 'The private package request is invalid.', 'wp-ai-bridge' ) );
	}

	/** @return array<string,mixed>|WP_Error */
	public function install( $input ) {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'artifact_id', 'sha256', 'kind' ) ) ||
			! $this->can_install( $input ) || ! isset( $input['artifact_id'], $input['sha256'] ) ||
			! is_string( $input['artifact_id'] ) || ! is_string( $input['sha256'] ) ) {
			return new WP_Error( 'private_package_permission_denied', __( 'Private package authorization is not available.', 'wp-ai-bridge' ) );
		}
		return $this->store->install( $input['artifact_id'], $input['sha256'], $input['kind'], OAuth_Server::authenticated_mcp_client_id() );
	}

	public function render_upload_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage WP AI Bridge settings.', 'wp-ai-bridge' ) );
		}
		$clients = array_merge( array( OAuth_Server::CHATGPT_CLIENT_ID ), ( new Approved_OAuth_Clients() )->all() );
		$id      = isset( $_GET['artifact'] ) && is_string( $_GET['artifact'] ) ? sanitize_text_field( wp_unslash( $_GET['artifact'] ) ) : '';
		$result  = '' !== $id ? $this->store->inspect_for_browser( $id ) : null;
		$error   = isset( $_GET['error'] ) && is_string( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Private ZIP Packages', 'wp-ai-bridge' ); ?></h1>
			<p><?php echo esc_html__( 'Upload an untrusted plugin or theme ZIP into private Bridge staging. This does not install or activate code. The selected approved AI client must review the artifact and explicitly request its exact SHA-256 installation.', 'wp-ai-bridge' ); ?></p>
			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html__( 'Private ZIP upload failed. Verify the file, size, authorization and server storage, then retry.', 'wp-ai-bridge' ); ?> <code><?php echo esc_html( $error ); ?></code></p></div>
			<?php endif; ?>
			<?php if ( is_array( $result ) ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html__( 'Staged, not installed. The approved AI client can inspect this exact artifact before asking to install it.', 'wp-ai-bridge' ); ?></p>
				<p><strong><?php echo esc_html__( 'Artifact ID', 'wp-ai-bridge' ); ?>:</strong> <code><?php echo esc_html( $result['artifact_id'] ); ?></code></p>
				<p><strong>SHA-256:</strong> <code><?php echo esc_html( $result['sha256'] ); ?></code></p>
				<p><?php echo esc_html__( 'Staging expires in one hour. Installation never activates the extension.', 'wp-ai-bridge' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! $this->store->allowed( 'plugin' ) && ! $this->store->allowed( 'theme' ) ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html__( 'Enable Code & Extensions and External Packages, and verify native WordPress install authority, before staging a ZIP.', 'wp-ai-bridge' ); ?></p></div>
			<?php endif; ?>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::POST_ACTION ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><label for="wpai-private-kind"><?php echo esc_html__( 'Package type', 'wp-ai-bridge' ); ?></label></th>
						<td><select id="wpai-private-kind" name="kind" required><option value="plugin"><?php echo esc_html__( 'Plugin', 'wp-ai-bridge' ); ?></option><option value="theme"><?php echo esc_html__( 'Theme', 'wp-ai-bridge' ); ?></option></select></td></tr>
					<tr><th scope="row"><label for="wpai-private-client"><?php echo esc_html__( 'Approved AI client', 'wp-ai-bridge' ); ?></label></th>
						<td><select id="wpai-private-client" name="client_id" required>
							<?php foreach ( $clients as $client ) : ?>
								<option value="<?php echo esc_attr( $client ); ?>"><?php echo esc_html( $client ); ?></option>
							<?php endforeach; ?>
						</select></td></tr>
					<tr><th scope="row"><label for="wpai-private-zip"><?php echo esc_html__( 'Plugin or theme ZIP', 'wp-ai-bridge' ); ?></label></th>
						<td><input type="file" name="wpai_private_zip" id="wpai-private-zip" accept=".zip,application/zip" required>
						<p class="description"><?php echo esc_html__( 'Private, size-limited, one-hour staging; no automatic installation or activation.', 'wp-ai-bridge' ); ?></p></td></tr>
				</tbody></table>
				<?php submit_button( __( 'Stage ZIP for AI Review', 'wp-ai-bridge' ) ); ?>
			</form>
		</div>
		<?php
	}

	/** Browser-cookie authentication and nonce, never a generic OAuth bearer-to-REST expansion. */
	public function handle_browser_upload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage WP AI Bridge settings.', 'wp-ai-bridge' ) );
		}
		check_admin_referer( self::NONCE_ACTION );
		$kind   = isset( $_POST['kind'] ) && is_string( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$client = isset( $_POST['client_id'] ) && is_string( $_POST['client_id'] ) ? esc_url_raw( wp_unslash( $_POST['client_id'] ), array( 'https' ) ) : '';
		$upload = isset( $_FILES['wpai_private_zip'] ) ? $_FILES['wpai_private_zip'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP file descriptor is validated and never reflected.
		$result = $this->store->stage_browser_upload( $upload, $kind, $client );
		$query  = is_wp_error( $result )
			? array( 'error' => $result->get_error_code() )
			: array( 'artifact' => $result['artifact_id'] );
		wp_safe_redirect( add_query_arg( $query, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}
}
