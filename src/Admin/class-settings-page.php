<?php
/**
 * WP AI Bridge administration area.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Admin;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Environment;
use WP_AI_Bridge\Support\Administration_Configuration;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Native_Option_Access;
use WP_AI_Bridge\Support\Hook_Dispatch_Recovery;
use WP_AI_Bridge\Support\Payload_Settings;
use WP_AI_Bridge\Support\Transfer_Settings;
use WP_AI_Bridge\Support\Create_Claim;
use WP_AI_Bridge\Support\Create_Claim_Store;
use WP_AI_Bridge\Support\External_Package_Limits;
use WP_AI_Bridge\Support\REST_Invocation_Input_Limits;
use WP_AI_Bridge\Support\Operation_Receipt_Limits;
use WP_AI_Bridge\Support\Operation_Content_Limits;
use WP_AI_Bridge\Support\Workspace_Limits;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Workspace\Store;

/**
 * Renders the complete Bridge administration area.
 */
final class Settings_Page {
	const PAGE_SLUG      = 'wp-ai-bridge';
	const DOCUMENTS_SLUG = 'wp-ai-bridge-documents';
	const TASKS_SLUG     = 'wp-ai-bridge-tasks';
	const ACTIVITY_SLUG  = 'wp-ai-bridge-activity';
	const SETTINGS_SLUG  = 'wp-ai-bridge-settings';


	/** @var bool */
	private $settings_prepared = false;
	/** @var array */
	private $save_snapshot = array();
	/** @var array */
	private $save_result = array();
	/** @var array */
	private $sql_setup_result = array();
	/** @var array */
	private $settings_draft = array();
	/** @var string */
	private $draft_revision = '';
	/** @var string */
	private $selected_section = 'connection';

	/** @var Environment */
	private $environment;

	/** @var Settings */
	private $settings;

	/** @var OAuth_Server */
	private $oauth_server;

	/** @var Store */
	private $workspace;

	/** @var Mutation_Log */
	private $log;

	/**
	 * Creates the administration area.
	 *
	 * @param Environment       $environment  Runtime dependency inspector.
	 * @param Settings          $settings     Bridge settings service.
	 * @param OAuth_Server|null $oauth_server Optional direct ChatGPT OAuth/MCP service.
	 * @param Store|null        $workspace    Optional shared Workspace store.
	 * @param Mutation_Log|null $log          Optional shared mutation log.
	 */
	public function __construct( Environment $environment, Settings $settings, ?OAuth_Server $oauth_server = null, ?Store $workspace = null, ?Mutation_Log $log = null ) {
		$this->environment  = $environment;
		$this->settings     = $settings;
		$this->oauth_server = $oauth_server ? $oauth_server : new OAuth_Server();
		$this->workspace    = $workspace ? $workspace : new Store();
		$this->log          = $log ? $log : new Mutation_Log();
	}

	/**
	 * Registers the top-level admin menu and child screens.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'WP AI Bridge', 'wp-ai-bridge' ),
			__( 'WP AI Bridge', 'wp-ai-bridge' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-admin-site-alt3',
			58
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Documents', 'wp-ai-bridge' ),
			__( 'Documents', 'wp-ai-bridge' ),
			'manage_options',
			self::DOCUMENTS_SLUG,
			array( $this, 'render_documents' )
		);
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Tasks', 'wp-ai-bridge' ),
			__( 'Tasks', 'wp-ai-bridge' ),
			'manage_options',
			self::TASKS_SLUG,
			array( $this, 'render_tasks' )
		);
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Activity', 'wp-ai-bridge' ),
			__( 'Activity', 'wp-ai-bridge' ),
			'manage_options',
			self::ACTIVITY_SLUG,
			array( $this, 'render_activity' )
		);
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Settings', 'wp-ai-bridge' ),
			__( 'Settings', 'wp-ai-bridge' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_settings' )
		);

		// WordPress creates the parent page as the first submenu. Rename it without
		// registering a duplicate route so the requested navigation reads Dashboard.
		global $submenu;
		if ( isset( $submenu[ self::PAGE_SLUG ][0][0] ) ) {
			$submenu[ self::PAGE_SLUG ][0][0] = __( 'Dashboard', 'wp-ai-bridge' );
		}
	}

	/** Move the existing Settings row after submenus registered by other owners. @return void */
	public function order_settings_submenu() {
		global $submenu;
		if ( ! isset( $submenu[ self::PAGE_SLUG ] ) || ! is_array( $submenu[ self::PAGE_SLUG ] ) ) {
			return;
		}
		foreach ( $submenu[ self::PAGE_SLUG ] as $index => $entry ) {
			if ( isset( $entry[2] ) && self::SETTINGS_SLUG === $entry[2] ) {
				unset( $submenu[ self::PAGE_SLUG ][ $index ] );
				$submenu[ self::PAGE_SLUG ][] = $entry;
				return;
			}
		}
	}

	/**
	 * Compatibility renderer used by internal callers.
	 *
	 * @return void
	 */
	public function render() {
		$this->render_settings();
	}

	/**
	 * Renders the compact Workspace dashboard.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		$this->require_admin();
		$resume = $this->workspace->resume();
		if ( is_wp_error( $resume ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $resume->get_error_message() ) . '</p></div>';
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP AI Bridge', 'wp-ai-bridge' ); ?></h1>
			<p><?php echo esc_html__( 'Compact project orientation for the persistent Workspace. Live WordPress remains the source of truth for site content and configuration.', 'wp-ai-bridge' ); ?></p>

			<h2><?php echo esc_html__( 'Workspace overview', 'wp-ai-bridge' ); ?></h2>
			<table class="widefat striped" style="max-width: 900px">
				<tbody>
					<tr><th scope="row"><?php echo esc_html__( 'Current focus', 'wp-ai-bridge' ); ?></th><td><?php echo esc_html( $resume['current_focus'] ? $resume['current_focus'] : ( 'mixed' === $resume['scope'] ? __( 'Mixed Workspace; no single site-wide focus.', 'wp-ai-bridge' ) : __( 'No in-progress task', 'wp-ai-bridge' ) ) ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Documents', 'wp-ai-bridge' ); ?></th><td><?php echo esc_html( (string) $resume['counts']['documents'] ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Active tasks', 'wp-ai-bridge' ); ?></th><td><?php echo esc_html( (string) $resume['counts']['active_tasks'] ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Blocked tasks', 'wp-ai-bridge' ); ?></th><td><?php echo esc_html( (string) $resume['counts']['blocked'] ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Review needed', 'wp-ai-bridge' ); ?></th><td><?php echo esc_html( (string) $resume['counts']['review_needed'] ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Last Workspace update', 'wp-ai-bridge' ); ?></th><td><?php echo esc_html( $resume['last_modified_gmt'] ? $resume['last_modified_gmt'] : __( 'No Workspace data yet', 'wp-ai-bridge' ) ); ?></td></tr>
				</tbody>
			</table>

			<?php $this->render_connection_summary(); ?>

			<h2><?php echo esc_html__( 'Next active tasks', 'wp-ai-bridge' ); ?></h2>
			<?php $this->render_task_summary_table( array_slice( $resume['active_tasks'], 0, 8 ) ); ?>
		</div>
		<?php
	}

	/**
	 * Renders Workspace document inspection.
	 *
	 * @return void
	 */
	public function render_documents() {
		$this->require_admin();
		$status           = isset( $_GET['workspace_status'] ) ? sanitize_key( wp_unslash( $_GET['workspace_status'] ) ) : 'active'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$include_archived = in_array( $status, array( 'all', 'archived' ), true );
		$cursor           = isset( $_GET['workspace_cursor'] ) ? absint( $_GET['workspace_cursor'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$page             = $this->workspace->page_documents(
			array(
				'limit'            => 25,
				'before_id'        => $cursor,
				'include_archived' => $include_archived,
				'archived_only'    => 'archived' === $status,
			)
		);
		if ( is_wp_error( $page ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $page->get_error_message() ) . '</p></div>';
			return;
		}
		$documents = $page['items'];
		$view_id   = isset( $_GET['document'] ) ? absint( $_GET['document'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only record selection.
		$view      = $view_id ? $this->workspace->get_document( $view_id ) : null;
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Documents', 'wp-ai-bridge' ); ?></h1>
			<p><?php echo esc_html__( 'Durable Markdown-oriented project documents. These records are private Workspace data and are not normal Posts or Pages.', 'wp-ai-bridge' ); ?></p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::DOCUMENTS_SLUG . '&workspace_status=active' ) ); ?>"><?php echo esc_html__( 'Active', 'wp-ai-bridge' ); ?></a> |
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::DOCUMENTS_SLUG . '&workspace_status=archived' ) ); ?>"><?php echo esc_html__( 'Archived', 'wp-ai-bridge' ); ?></a> |
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::DOCUMENTS_SLUG . '&workspace_status=all' ) ); ?>"><?php echo esc_html__( 'All', 'wp-ai-bridge' ); ?></a>
			</p>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Title', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Project ID', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Key', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'State', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Version', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Modified (UTC)', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'View', 'wp-ai-bridge' ); ?></th></tr></thead>
				<tbody>
				<?php if ( ! $documents ) : ?>
					<tr><td colspan="7"><?php echo esc_html__( 'No Workspace documents found.', 'wp-ai-bridge' ); ?></td></tr><?php endif; ?>
				<?php foreach ( $documents as $document ) : ?>
					<tr>
						<td><?php echo esc_html( $document['title'] ); ?></td>
						<td><?php $this->render_project_id( $document['project_ref'] ?? '' ); ?></td>
						<td><code dir="ltr"><?php echo esc_html( $document['key'] ); ?></code></td>
						<td><?php echo esc_html( $document['archived'] ? __( 'Archived', 'wp-ai-bridge' ) : __( 'Active', 'wp-ai-bridge' ) ); ?></td>
						<td><?php echo esc_html( (string) $document['version'] ); ?></td>
						<td><?php echo esc_html( $document['modified_gmt'] ); ?></td>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::DOCUMENTS_SLUG . '&workspace_status=' . rawurlencode( $status ) . '&document=' . (int) $document['id'] ) ); ?>"><?php echo esc_html__( 'Inspect', 'wp-ai-bridge' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				<?php
				/* translators: 1: documents returned on this page, 2: total matching documents. */
				echo esc_html( sprintf( __( 'Showing %1$d of %2$d matching Workspace documents.', 'wp-ai-bridge' ), $page['returned'], $page['total'] ) );
				?>
			</p>
			<?php if ( $page['has_more'] ) : ?>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::DOCUMENTS_SLUG . '&workspace_status=' . rawurlencode( $status ) . '&workspace_cursor=' . (int) $page['next_cursor'] ) ); ?>"><?php echo esc_html__( 'Older Workspace documents', 'wp-ai-bridge' ); ?> &rarr;</a></p>
			<?php endif; ?>

			<?php if ( is_array( $view ) ) : ?>
				<h2><?php echo esc_html( $view['title'] ); ?></h2>
				<p><strong><?php echo esc_html__( 'Project ID', 'wp-ai-bridge' ); ?></strong> <?php $this->render_project_id( $view['project_ref'] ?? '' ); ?></p>
				<p><strong><?php echo esc_html__( 'State hash:', 'wp-ai-bridge' ); ?></strong> <code dir="ltr"><?php echo esc_html( $view['state_hash'] ); ?></code></p>
				<pre dir="ltr" style="direction: ltr; text-align: left; unicode-bidi: isolate; white-space: pre-wrap; overflow-wrap: anywhere; max-width: 1100px"><?php echo esc_html( $view['content'] ); ?></pre>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders Workspace task inspection and filtering.
	 *
	 * @return void
	 */
	public function render_tasks() {
		$this->require_admin();
		$filters = array(
			'include_archived' => ! empty( $_GET['include_archived'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
			'progress'         => isset( $_GET['progress'] ) ? sanitize_key( wp_unslash( $_GET['progress'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
			'review'           => isset( $_GET['review'] ) ? sanitize_key( wp_unslash( $_GET['review'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
			'delivery'         => isset( $_GET['delivery'] ) ? sanitize_key( wp_unslash( $_GET['delivery'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		);
		$cursor  = isset( $_GET['workspace_cursor'] ) ? absint( $_GET['workspace_cursor'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$page    = $this->workspace->page_tasks(
			array_merge(
				$filters,
				array(
					'limit'     => 25,
					'before_id' => $cursor,
				)
			)
		);
		if ( is_wp_error( $page ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $page->get_error_message() ) . '</p></div>';
			return;
		}
		$tasks   = $page['items'];
		$view_id = isset( $_GET['task'] ) ? absint( $_GET['task'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only record selection.
		$view    = $view_id ? $this->workspace->get_task( $view_id ) : null;
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Tasks', 'wp-ai-bridge' ); ?></h1>
			<p><?php echo esc_html__( 'Lightweight durable work state. Progress, review, and delivery are independent facts.', 'wp-ai-bridge' ); ?></p>
			<form method="get" style="margin: 16px 0 18px;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::TASKS_SLUG ); ?>">
				<label><?php echo esc_html__( 'Progress', 'wp-ai-bridge' ); ?> <select name="progress"><option value=""><?php echo esc_html__( 'Any', 'wp-ai-bridge' ); ?></option><?php $this->render_options( array( 'todo', 'in_progress', 'blocked', 'done' ), $filters['progress'] ); ?></select></label>
				<label><?php echo esc_html__( 'Review', 'wp-ai-bridge' ); ?> <select name="review"><option value=""><?php echo esc_html__( 'Any', 'wp-ai-bridge' ); ?></option><?php $this->render_options( array( 'not_required', 'pending', 'changes_requested', 'approved' ), $filters['review'] ); ?></select></label>
				<label><?php echo esc_html__( 'Delivery', 'wp-ai-bridge' ); ?> <select name="delivery"><option value=""><?php echo esc_html__( 'Any', 'wp-ai-bridge' ); ?></option><?php $this->render_options( array( 'not_applicable', 'draft_preview', 'live' ), $filters['delivery'] ); ?></select></label>
				<label><input type="checkbox" name="include_archived" value="1" <?php checked( $filters['include_archived'] ); ?>> <?php echo esc_html__( 'Include archived', 'wp-ai-bridge' ); ?></label>
				<?php submit_button( __( 'Filter', 'wp-ai-bridge' ), 'secondary', '', false ); ?>
			</form>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Title', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Project ID', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Progress', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Review', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Delivery', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Version', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Modified (UTC)', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'View', 'wp-ai-bridge' ); ?></th></tr></thead>
				<tbody>
				<?php
				if ( ! $tasks ) :
					?>
					<tr><td colspan="8"><?php echo esc_html__( 'No Workspace tasks found.', 'wp-ai-bridge' ); ?></td></tr><?php endif; ?>
				<?php foreach ( $tasks as $task ) : ?>
					<tr><td><?php echo esc_html( $task['title'] ); ?></td><td><?php $this->render_project_id( $task['project_ref'] ?? '' ); ?></td><td><?php echo esc_html( $this->enum_label( $task['progress'] ) ); ?></td><td><?php echo esc_html( $this->enum_label( $task['review'] ) ); ?></td><td><?php echo esc_html( $this->enum_label( $task['delivery'] ) ); ?></td><td><?php echo esc_html( (string) $task['version'] ); ?></td><td><?php echo esc_html( $task['modified_gmt'] ); ?></td><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::TASKS_SLUG . '&task=' . (int) $task['id'] ) ); ?>"><?php echo esc_html__( 'Inspect', 'wp-ai-bridge' ); ?></a></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				<?php
				/* translators: 1: tasks returned on this page, 2: total matching tasks. */
				echo esc_html( sprintf( __( 'Showing %1$d of %2$d matching Workspace tasks.', 'wp-ai-bridge' ), $page['returned'], $page['total'] ) );
				?>
			</p>
			<?php if ( $page['has_more'] ) : ?>
				<?php
				$next_query = array(
					'page'             => self::TASKS_SLUG,
					'workspace_cursor' => (int) $page['next_cursor'],
				);
				foreach ( $filters as $filter => $value ) {
					if ( $value ) {
						$next_query[ $filter ] = true === $value ? '1' : $value;
					}
				}
				?>
				<p><a href="<?php echo esc_url( add_query_arg( $next_query, admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Older Workspace tasks', 'wp-ai-bridge' ); ?> &rarr;</a></p>
			<?php endif; ?>

			<?php if ( is_array( $view ) ) : ?>
				<h2><?php echo esc_html( $view['title'] ); ?></h2>
				<p><strong><?php echo esc_html__( 'Project ID', 'wp-ai-bridge' ); ?></strong> <?php $this->render_project_id( $view['project_ref'] ?? '' ); ?></p>
				<p><strong><?php echo esc_html__( 'Goal:', 'wp-ai-bridge' ); ?></strong> <?php echo esc_html( $view['goal'] ); ?></p>
				<p><strong><?php echo esc_html__( 'Notes:', 'wp-ai-bridge' ); ?></strong></p><pre dir="ltr" style="direction: ltr; text-align: left; unicode-bidi: isolate; white-space: pre-wrap; overflow-wrap: anywhere; max-width: 1100px"><?php echo esc_html( $view['notes'] ); ?></pre>
				<p><strong><?php echo esc_html__( 'State hash:', 'wp-ai-bridge' ); ?></strong> <code dir="ltr"><?php echo esc_html( $view['state_hash'] ); ?></code></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the bounded metadata-only activity log.
	 *
	 * @return void
	 */
	public function render_activity() {
		$this->require_admin();
		$entries = $this->log->recent( Mutation_Log::LIMIT );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Activity', 'wp-ai-bridge' ); ?></h1>
			<p><?php echo esc_html__( 'Recent metadata-only Bridge activity; at most 50 entries are stored with a 30-day display window. This screen excludes content, secrets and request bodies. Failures before the request reaches WordPress are not captured here.', 'wp-ai-bridge' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Time (UTC)', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'User', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Ability', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Operation', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Target', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Result', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Reference', 'wp-ai-bridge' ); ?></th></tr></thead>
				<tbody>
				<?php
				if ( ! $entries ) :
					?>
					<tr><td colspan="7"><?php echo esc_html__( 'No recent Bridge activity.', 'wp-ai-bridge' ); ?></td></tr><?php endif; ?>
				<?php foreach ( $entries as $entry ) : ?>
					<tr><td><?php echo esc_html( $entry['timestamp'] ); ?></td><td><?php echo esc_html( (string) $entry['user_id'] ); ?></td><td><code><?php echo esc_html( $entry['ability'] ); ?></code></td><td><code dir="ltr"><?php echo esc_html( (string) ( $entry['operation'] ?? '' ) ); ?></code></td><td><?php echo esc_html( $entry['target_type'] . ( $entry['target_id'] ? ':' . $entry['target_id'] : '' ) ); ?></td><td><?php echo esc_html( $entry['success'] ? __( 'Success', 'wp-ai-bridge' ) : __( 'Failed', 'wp-ai-bridge' ) ); ?>
					<?php
					if ( ! empty( $entry['error_code'] ) ) :
						?>
						— <code><?php echo esc_html( $entry['error_code'] ); ?></code><?php endif; ?></td><td><code dir="ltr"><?php echo esc_html( (string) ( $entry['event_ref'] ?? '' ) ); ?></code></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders connection, access, export, and destructive Workspace settings.
	 *
	 * @return void
	 */
	/**
	 * Handles same-screen settings POSTs before the WordPress admin header.
	 *
	 * @return void
	 */
	public function prepare_settings_screen() {
		if ( isset( $_GET['page'] ) && self::SETTINGS_SLUG === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Route selection only.
			$this->prepare_settings_state();
		}
	}

	/** @param string $hook_suffix Current admin screen. @return void */
	public function enqueue_settings_assets( $hook_suffix ) {
		if ( 'wp-ai-bridge_page_' . self::SETTINGS_SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'wp-ai-bridge-settings', plugins_url( 'src/Admin/settings.css', WP_AI_BRIDGE_FILE ), array(), WP_AI_BRIDGE_VERSION );
		wp_enqueue_script( 'wp-ai-bridge-settings', plugins_url( 'src/Admin/settings.js', WP_AI_BRIDGE_FILE ), array(), WP_AI_BRIDGE_VERSION, true );
		wp_localize_script(
			'wp-ai-bridge-settings',
			'wpaiSettingsStrings',
			array(
				'clean'    => __( 'No unsaved changes.', 'wp-ai-bridge' ),
				'dirty'    => __( 'Unsaved changes.', 'wp-ai-bridge' ),
				'saving'   => __( 'Saving changes…', 'wp-ai-bridge' ),
				'changed'  => __( 'Unsaved changes', 'wp-ai-bridge' ),
				'remove'   => __( 'Remove this scope on save', 'wp-ai-bridge' ),
				'undo'     => __( 'Keep this scope', 'wp-ai-bridge' ),
				'newScope' => __( 'New directory scope', 'wp-ai-bridge' ),
				'review'   => __( 'Review required', 'wp-ai-bridge' ),
				'leave'    => __( 'You have unsaved settings changes.', 'wp-ai-bridge' ),
			)
		);
	}

	/** Load a request-local draft; no transient or URL ever stores submitted values. @return void */
	private function prepare_settings_state() {
		if ( $this->settings_prepared ) {
			return;
		}
		$this->settings_prepared = true;
		$this->require_admin();
		nocache_headers();
		$save                = new Settings_Save( $this->settings );
		$this->save_snapshot = $save->snapshot();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		$posted = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Each separate controller verifies its own nonce and current context before effects.
		if ( Native_SQL_Config_Setup::ACTION === ( $posted['action'] ?? null ) ) {
			$this->selected_section = 'database';
			$this->sql_setup_result = ( new Native_SQL_Config_Setup() )->activate( $posted );
			return;
		}
		if ( Settings_Save::ACTION !== ( $posted['action'] ?? null ) ) {
			return;
		}
		$section = isset( $posted['selected_section'] ) && is_string( $posted['selected_section'] ) ? $posted['selected_section'] : '';
		if ( isset( $this->settings_sections()[ $section ] ) ) {
			$this->selected_section = $section;
		}
		if ( isset( $posted['wpai_add_scope'] ) ) {
			$this->prepare_scope_draft( $posted, $save );
		} else {
			$this->save_result = $save->save( $posted );
		}
		if ( ! in_array( $this->save_result['status'] ?? '', array( 'saved', 'unchanged' ), true ) ) {
			$this->settings_draft = $this->save_result['draft'] ?? array();
			$this->draft_revision = is_string( $posted['expected_revision'] ?? null ) ? $posted['expected_revision'] : '';
		}
		$this->save_snapshot = $save->snapshot();
	}

	/** Add a row without persisting any setting, including when JavaScript is unavailable. @param array $posted Draft. @param Settings_Save $save Controller. @return void */
	private function prepare_scope_draft( array $posted, Settings_Save $save ) {
		$snapshot = $this->save_snapshot;
		$valid    = $snapshot['valid'] && is_string( $posted['_wpnonce'] ?? null ) && wp_verify_nonce( $posted['_wpnonce'], $save->nonce_action() );
		foreach ( array( 'blog_id', 'network_id', 'user_id' ) as $key ) {
			$valid = $valid && ( $posted[ $key ] ?? null ) === (string) $snapshot[ $key ];
		}
		$valid = $valid && '1' === ( $posted['form_complete'] ?? null ) && is_string( $posted['expected_revision'] ?? null ) && hash_equals( $snapshot['revision'], $posted['expected_revision'] );
		$draft = array_intersect_key( $posted, array_fill_keys( $snapshot['editable_options'], true ) );
		if ( ! $valid || ! in_array( Administration_Configuration::OPTION_NAME, $snapshot['editable_options'], true ) ) {
			$this->save_result = array(
				'status' => 'stale',
				'draft'  => $draft,
				'errors' => array( '_form' => __( 'The scope draft could not be added. Settings or administrator authority changed; reload and review before saving.', 'wp-ai-bridge' ) ),
			);
			return;
		}
		if ( ! isset( $draft[ Administration_Configuration::OPTION_NAME ] ) || ! is_array( $draft[ Administration_Configuration::OPTION_NAME ] ) ) {
			$draft[ Administration_Configuration::OPTION_NAME ] = ( new Administration_Configuration() )->all();
		}
		$roots   = $draft[ Administration_Configuration::OPTION_NAME ]['roots'] ?? array();
		$roots   = is_array( $roots ) ? array_values( $roots ) : array();
		$roots[] = array();
		$draft[ Administration_Configuration::OPTION_NAME ]['roots'] = $roots;
		$this->save_result      = array(
			'status' => 'draft',
			'draft'  => $draft,
			'errors' => array(),
		);
		$this->selected_section = 'files-backups';
	}

	/** @return array Stable section identity and localized labels. */
	private function settings_sections() {
		return array(
			'connection'        => __( 'Connection', 'wp-ai-bridge' ),
			'content-site'      => __( 'Content & site', 'wp-ai-bridge' ),
			'accounts-settings' => __( 'Accounts & sensitive settings', 'wp-ai-bridge' ),
			'code-integrations' => __( 'Code & integrations', 'wp-ai-bridge' ),
			'database'          => __( 'Database', 'wp-ai-bridge' ),
			'files-backups'     => __( 'Files & backups', 'wp-ai-bridge' ),
			'capacity'          => __( 'Capacity & limits', 'wp-ai-bridge' ),
			'workspace-data'    => __( 'Workspace data', 'wp-ai-bridge' ),
		);
	}

	/** @param string $option Native store. @return bool Whether it is editable in this exact context. */
	private function editable_option( $option ) {
		return in_array( $option, $this->save_snapshot['editable_options'] ?? array(), true );
	}

	/** @param string $option Native store. @param mixed $saved Current value. @return mixed Escaped at the output boundary. */
	private function draft_option( $option, $saved ) {
		if ( ! array_key_exists( $option, $this->settings_draft ) || ! $this->editable_option( $option ) ) {
			return $saved;
		}
		$draft = $this->settings_draft[ $option ];
		return is_array( $saved ) && is_array( $draft ) ? array_replace( $saved, $draft ) : $draft;
	}

	/** @return array Native stores can span multiple visual sections. */
	private function option_sections() {
		return array(
			Settings::OPTION_NAME                     => array( 'content-site', 'accounts-settings', 'code-integrations' ),
			Settings::DATABASE_OPTION_NAME            => array( 'database' ),
			Settings::ADMINISTRATION_OPTION_NAME      => array( 'content-site', 'code-integrations', 'files-backups' ),
			Native_Option_Access::NETWORK_OPTION_NAME => array( 'accounts-settings' ),
			Native_Option_Access::NETWORK_WRITE_OPTION_NAME => array( 'accounts-settings' ),
			Administration_Configuration::OPTION_NAME => array( 'files-backups', 'capacity' ),
			Payload_Settings::OPTION_NAME             => array( 'capacity' ),
			Transfer_Settings::OPTION_NAME            => array( 'capacity' ),
			Workspace_Limits::OPTION_NAME             => array( 'capacity' ),
			Operation_Receipt_Limits::OPTION_NAME     => array( 'capacity' ),
			Operation_Content_Limits::OPTION_NAME     => array( 'capacity' ),
			REST_Invocation_Input_Limits::OPTION_NAME => array( 'capacity' ),
			External_Package_Limits::OPTION_NAME      => array( 'capacity' ),
		);
	}

	/** @param string $field Error key. @return array Affected visual sections. */
	private function error_sections( $field ) {
		foreach ( $this->option_sections() as $option => $sections ) {
			if ( $field === $option || 0 === strpos( $field, $option . '[' ) || 0 === strpos( $field, $option . '.' ) ) {
				return $sections;
			}
		}
		return array();
	}

	/** @return void */
	public function render_settings() {
		$this->require_admin();
		$this->prepare_settings_state();
		$sections = $this->settings_sections();
		$status   = $this->save_result['status'] ?? '';
		$pending  = in_array( $status, array( 'draft', 'invalid', 'forbidden', 'stale', 'partial', 'rejected' ), true );
		$blocked  = ! ( $this->save_snapshot['valid'] ?? false ) || in_array( $status, array( 'forbidden', 'stale', 'partial', 'rejected' ), true );
		$errors   = $this->save_result['errors'] ?? array();
		$affected = array();
		foreach ( array_keys( $errors ) as $field ) {
			$affected = array_merge( $affected, $this->error_sections( $field ) );
		}
		$save     = new Settings_Save( $this->settings );
		$revision = $pending ? $this->draft_revision : ( $this->save_snapshot['revision'] ?? '' );
		?>
		<div class="wrap wpai-settings" data-section="<?php echo esc_attr( $this->selected_section ); ?>" data-pending="<?php echo $pending ? '1' : '0'; ?>" data-blocked="<?php echo $blocked ? '1' : '0'; ?>">
			<h1><?php echo esc_html__( 'WP AI Bridge Settings', 'wp-ai-bridge' ); ?></h1>
			<p class="wpai-settings-intro"><?php echo esc_html__( 'Choose a section. Save changes applies your edits in all sections together.', 'wp-ai-bridge' ); ?></p>
			<?php $this->render_save_feedback( $status, $errors, $blocked ); ?>
			<?php $this->render_sql_setup_feedback(); ?>
			<div class="wpai-settings-layout">
				<nav class="wpai-settings-nav" aria-label="<?php echo esc_attr__( 'Settings sections', 'wp-ai-bridge' ); ?>">
					<ul>
					<?php foreach ( $sections as $id => $label ) : ?>
						<li><a href="#<?php echo esc_attr( $id ); ?>" data-section-link="<?php echo esc_attr( $id ); ?>"<?php echo $id === $this->selected_section ? ' aria-current="location"' : ''; ?>><?php echo esc_html( $label ); ?><span class="wpai-section-dirty" hidden> — <?php echo esc_html__( 'Unsaved changes', 'wp-ai-bridge' ); ?></span>
						<?php
						if ( in_array( $id, $affected, true ) ) :
							?>
							<span class="wpai-section-error"> — <?php echo esc_html__( 'Review required', 'wp-ai-bridge' ); ?></span><?php endif; ?></a></li>
					<?php endforeach; ?>
					</ul>
				</nav>
				<div class="wpai-settings-content">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>" id="wpai-settings-form">
						<?php // The first native submitter owns implicit Enter in both JS and no-JS. It has no name, so only the global action is submitted. ?>
						<input type="submit" id="wpai-settings-default-submit" hidden tabindex="-1" value="" <?php disabled( $blocked ); ?>>
						<input type="hidden" name="action" value="<?php echo esc_attr( Settings_Save::ACTION ); ?>">
						<?php wp_nonce_field( $save->nonce_action() ); ?>
						<input type="hidden" name="expected_revision" value="<?php echo esc_attr( $revision ); ?>">
						<?php foreach ( array( 'blog_id', 'network_id', 'user_id' ) as $context ) : ?>
							<input type="hidden" name="<?php echo esc_attr( $context ); ?>" value="<?php echo esc_attr( (string) ( $this->save_snapshot[ $context ] ?? 0 ) ); ?>">
						<?php endforeach; ?>
						<input type="hidden" name="selected_section" id="wpai-selected-section" value="<?php echo esc_attr( $this->selected_section ); ?>">
						<section id="connection" class="wpai-settings-section" aria-labelledby="connection-heading">
							<h2 id="connection-heading" tabindex="-1"><?php echo esc_html( $sections['connection'] ); ?></h2>
							<p><?php echo esc_html__( 'These are local configuration observations, not a live client connection check. Configure a compatible authenticated OAuth/MCP client using the endpoint shown below.', 'wp-ai-bridge' ); ?></p>
							<?php $this->render_connection_diagnostics(); ?>
							<p><strong><?php echo esc_html__( 'Client setup:', 'wp-ai-bridge' ); ?></strong> <?php echo esc_html__( 'After resolving local prerequisites, configure the MCP URL in your client, choose OAuth, authenticate with WordPress, then rescan its tools. Successful OAuth does not grant WordPress or Bridge permissions.', 'wp-ai-bridge' ); ?></p>
						</section>
						<?php $this->render_permission_sections(); ?>
						<section id="files-backups" class="wpai-settings-section" aria-labelledby="files-backups-heading">
							<h2 id="files-backups-heading" tabindex="-1"><?php echo esc_html( $sections['files-backups'] ); ?></h2>
							<?php $this->render_grant_group( __( 'Allowed operations', 'wp-ai-bridge' ), array( Settings::GROUP_FILESYSTEM_READ, Settings::GROUP_FILESYSTEM_WRITE, Settings::GROUP_FILESYSTEM_DELETE, Settings::GROUP_FILESYSTEM_EXECUTE, Settings::GROUP_LOGS_READ, Settings::GROUP_BACKUP_EXPORT, Settings::GROUP_BACKUP_RESTORE ) ); ?>
							<?php $this->render_scope_settings(); ?>
						</section>
						<section id="capacity" class="wpai-settings-section" aria-labelledby="capacity-heading">
							<h2 id="capacity-heading" tabindex="-1"><?php echo esc_html( $sections['capacity'] ); ?></h2>
							<?php $this->render_capacity_settings(); ?>
						</section>
						<input type="hidden" name="form_complete" value="1">
					</form>
					<?php $this->render_sql_setup_form(); ?>
					<section id="workspace-data" class="wpai-settings-section" aria-labelledby="workspace-data-heading">
						<h2 id="workspace-data-heading" tabindex="-1"><?php echo esc_html( $sections['workspace-data'] ); ?></h2>
						<?php $this->render_workspace_operations(); ?>
					</section>
				</div>
			</div>
			<div class="wpai-settings-savebar">
				<button type="submit" form="wpai-settings-form" class="button button-primary" id="wpai-settings-save"<?php disabled( $blocked ); ?>><?php echo esc_html__( 'Save changes', 'wp-ai-bridge' ); ?></button>
				<div><strong id="wpai-settings-dirty" role="status" aria-live="polite"><?php echo $pending ? esc_html__( 'Unsaved changes.', 'wp-ai-bridge' ) : esc_html__( 'No unsaved changes.', 'wp-ai-bridge' ); ?></strong><span class="description"><?php echo esc_html__( 'Saves changes in all sections. Workspace export and clear are separate actions.', 'wp-ai-bridge' ); ?></span></div>
			</div>
		</div>
		<?php
	}

	/** @param string $status Save status. @param array $errors Error text. @param bool $blocked Reload required. @return void */
	private function render_save_feedback( $status, array $errors, $blocked ) {
		if ( in_array( $status, array( 'saved', 'unchanged' ), true ) ) {
			?>
			<div class="notice notice-success"><p><?php echo 'saved' === $status ? esc_html__( 'Changes saved.', 'wp-ai-bridge' ) : esc_html__( 'Settings are unchanged.', 'wp-ai-bridge' ); ?></p></div>
			<?php
		}
		if ( 'draft' === $status ) {
			?>
			<div class="notice notice-info"><p><?php echo esc_html__( 'Directory scope added to your draft. No settings have been saved.', 'wp-ai-bridge' ); ?></p></div>
			<?php
		}
		if ( $errors || $blocked ) {
			?>
			<div class="notice notice-error wpai-settings-errors" id="wpai-settings-errors" tabindex="-1" role="alert">
				<p><strong><?php echo 'partial' === $status ? esc_html__( 'Some changes could not be confirmed.', 'wp-ai-bridge' ) : esc_html__( 'Review settings before saving.', 'wp-ai-bridge' ); ?></strong></p>
				<?php
				if ( $errors ) :
					?>
					<ul>
					<?php foreach ( $errors as $field => $message ) : ?>
						<?php $sections = $this->error_sections( $field ); ?>
					<li>
						<?php
						if ( $sections ) :
							?>
						<a href="#<?php echo esc_attr( $sections[0] ); ?>" data-error-option="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $message ); ?></a>
							<?php
else :
	?>
						<?php echo esc_html( $message ); ?><?php endif; ?></li>
				<?php endforeach; ?>
				</ul><?php endif; ?>
				<?php
				if ( $blocked ) :
					?>
					<p><?php echo esc_html__( 'Your submitted values remain below. Review the stored settings in another tab, then reload this screen to reconcile before saving again.', 'wp-ai-bridge' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html__( 'Open saved settings', 'wp-ai-bridge' ); ?></a> · <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>"><?php echo esc_html__( 'Reload and discard this draft', 'wp-ai-bridge' ); ?></a></p><?php endif; ?>
			</div>
			<?php
		}
		if ( ! empty( $this->save_result['outcomes'] ) && ! in_array( $status, array( 'saved', 'unchanged' ), true ) ) {
			$labels = array(
				'saved'         => __( 'Saved and verified', 'wp-ai-bridge' ),
				'unchanged'     => __( 'Unchanged', 'wp-ai-bridge' ),
				'rejected'      => __( 'Rejected', 'wp-ai-bridge' ),
				'unverified'    => __( 'Result unverified', 'wp-ai-bridge' ),
				'not_attempted' => __( 'Not saved', 'wp-ai-bridge' ),
			);
			?>
			<details class="wpai-save-outcomes"><summary><?php echo esc_html__( 'Save results by settings group', 'wp-ai-bridge' ); ?></summary><ul>
			<?php
			foreach ( $this->save_result['outcomes'] as $option => $outcome ) {
				$ids    = $this->option_sections()[ $option ] ?? array();
				$titles = array_intersect_key( $this->settings_sections(), array_fill_keys( $ids, true ) );
				?>
				<li><?php echo esc_html( implode( ', ', $titles ) . ': ' . ( $labels[ $outcome ] ?? __( 'Result unverified', 'wp-ai-bridge' ) ) ); ?></li>
				<?php
			}
			?>
			</ul></details>
			<?php
		}
	}

	/** @return void */
	private function render_permission_sections() {
		$sections = $this->settings_sections();
		$groups   = array(
			'content-site'      => array(
				array( __( 'Content and media', 'wp-ai-bridge' ), array( Settings::GROUP_SITE_READ, Settings::GROUP_BUILDER_WRITE, Settings::GROUP_REMOTE_MEDIA, Settings::GROUP_LIVE_CONTENT, Settings::GROUP_COMMENTS ) ),
				array( __( 'Site configuration', 'wp-ai-bridge' ), array( Settings::GROUP_SITE_CONFIG, Settings::GROUP_ADVANCED_METADATA, Settings::GROUP_NATIVE_CONFIGURATION, Settings::GROUP_CUSTOMIZER_WRITE ) ),
			),
			'accounts-settings' => array(
				array( __( 'Accounts and destructive operations', 'wp-ai-bridge' ), array( Settings::GROUP_AUTHENTICATION, Settings::GROUP_USERS_DESTRUCTIVE ) ),
				array( __( 'Current site settings', 'wp-ai-bridge' ), array( Settings::GROUP_NATIVE_OPTIONS_READ, Settings::GROUP_NATIVE_OPTIONS_WRITE ) ),
				array( __( 'Current network settings', 'wp-ai-bridge' ), array( Settings::GROUP_NATIVE_NETWORK_READ, Settings::GROUP_NATIVE_NETWORK_WRITE ) ),
			),
			'code-integrations' => array(
				array( __( 'Code and extensions', 'wp-ai-bridge' ), array( Settings::GROUP_CODE_EXTENSIONS, Settings::GROUP_EXTERNAL_PACKAGES, Settings::GROUP_SOURCE_EDITING ) ),
				array( __( 'Registered operations', 'wp-ai-bridge' ), array( Settings::GROUP_NATIVE_ABILITIES, Settings::GROUP_REST_DISCOVERY, Settings::GROUP_REST_INVOCATION ) ),
				array( __( 'Scheduled actions', 'wp-ai-bridge' ), array( Settings::GROUP_SCHEDULER_MANAGE, Settings::GROUP_SCHEDULER_RUN ) ),
			),
			'database'          => array( array( __( 'Database authority', 'wp-ai-bridge' ), Settings::DATABASE_GROUPS ) ),
		);
		foreach ( $groups as $id => $fieldsets ) {
			?>
			<section id="<?php echo esc_attr( $id ); ?>" class="wpai-settings-section" aria-labelledby="<?php echo esc_attr( $id . '-heading' ); ?>">
				<h2 id="<?php echo esc_attr( $id . '-heading' ); ?>" tabindex="-1"><?php echo esc_html( $sections[ $id ] ); ?></h2>
				<p><?php echo esc_html__( 'Enabled grants make matching Bridge abilities available. The connected WordPress user still needs the required native capabilities. OAuth authorization does not bypass these checks.', 'wp-ai-bridge' ); ?></p>
				<?php if ( 'database' === $id ) : ?>
					<p><?php echo esc_html__( 'Typed database writes bypass WordPress and plugin business APIs. Review the exact schema and row state before changing live data; use the matching native feature when its lifecycle is required.', 'wp-ai-bridge' ); ?></p>
					<p class="wpai-policy-status"><strong><?php echo esc_html__( 'Server SQL policy:', 'wp-ai-bridge' ); ?></strong> <?php echo esc_html( defined( 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL' ) && true === WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL ? __( 'Native-account SQL is allowed by server policy. The separate grant and native privileges are still required.', 'wp-ai-bridge' ) : __( 'Native-account SQL is disabled by server policy. The hosting operator must explicitly enable WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL before this grant can be used.', 'wp-ai-bridge' ) ); ?></p>
					<?php $this->render_sql_setup_status(); ?>
				<?php endif; ?>
				<?php foreach ( $fieldsets as $fieldset ) : ?>
					<?php $this->render_grant_group( $fieldset[0], $fieldset[1] ); ?>
				<?php endforeach; ?>
			</section>
			<?php
		}
	}

	/** @param int $line Existing false definition line. @return string */
	private function sql_manual_false_instruction( $line ) {
		/* translators: %d: line number of an existing explicit false definition. */
		return sprintf( __( 'Replace the existing false definition at line %d; do not add a second definition.', 'wp-ai-bridge' ), $line );
	}

	/** @param int $line Existing WordPress bootstrap line. @return string */
	private function sql_manual_ready_instruction( $line ) {
		/* translators: %d: line number of the exact standalone WordPress bootstrap statement. */
		return sprintf( __( 'Insert this exact line before the standalone WordPress bootstrap statement currently at line %d.', 'wp-ai-bridge' ), $line );
	}

	/** Explain the separate file-configuration result without treating it as a settings save. @return void */
	private function render_sql_setup_feedback() {
		if ( ! $this->sql_setup_result ) {
			return;
		}
		$written = 'written' === ( $this->sql_setup_result['result'] ?? '' );
		?>
		<div class="notice <?php echo $written ? 'notice-success' : 'notice-warning'; ?> wpai-sql-config-feedback" role="status">
			<p><strong><?php echo $written ? esc_html__( 'Configuration file updated; fresh-request verification required.', 'wp-ai-bridge' ) : esc_html__( 'Configuration setup was not completed.', 'wp-ai-bridge' ); ?></strong></p>
			<p><?php echo esc_html( $this->sql_setup_result['reason'] ?? '' ); ?></p>
		</div>
		<?php
	}

	/** Explain actual runtime/config state and provide a separate no-JS setup submitter. @return void */
	private function render_sql_setup_status() {
		$setup = new Native_SQL_Config_Setup();
		$state = $setup->inspect();
		?>
		<div class="wpai-sql-config">
			<h3><?php echo esc_html__( 'Native-account SQL server setup', 'wp-ai-bridge' ); ?></h3>
			<p><?php echo esc_html( $state['reason'] ); ?></p>
			<?php if ( $state['enabled'] ) : ?>
				<p><strong><?php echo esc_html__( 'Runtime verified: boolean true in this request.', 'wp-ai-bridge' ); ?></strong></p>
			<?php else : ?>
				<p><?php echo esc_html__( 'The configuration action does not save settings, issue SQL, change privileges or enable database grants.', 'wp-ai-bridge' ); ?></p>
				<?php if ( $state['path'] ) : ?>
					<p><strong><?php echo esc_html__( 'Loaded configuration file:', 'wp-ai-bridge' ); ?></strong> <code dir="ltr"><?php echo esc_html( $state['path'] ); ?></code></p>
					<?php if ( in_array( $state['state'], array( 'ready', 'false' ), true ) ) : ?>
						<p>
						<?php
						echo esc_html(
							'false' === $state['state']
								? $this->sql_manual_false_instruction( $state['line'] )
								: $this->sql_manual_ready_instruction( $state['line'] )
						);
						?>
						</p>
						<p><code dir="ltr" class="wpai-sql-config-line"><?php echo esc_html( Native_SQL_Config_Setup::DEFINITION ); ?></code></p>
					<?php elseif ( in_array( $state['state'], array( 'complex', 'external', 'unsupported', 'unknown' ), true ) ) : ?>
						<p><?php echo esc_html__( 'Locate the actual definition or bootstrap first. Edit only its intended server-policy location; do not append this line blindly:', 'wp-ai-bridge' ); ?></p>
						<p><code dir="ltr" class="wpai-sql-config-line"><?php echo esc_html( Native_SQL_Config_Setup::DEFINITION ); ?></code></p>
					<?php endif; ?>
				<?php else : ?>
					<p><?php echo esc_html__( 'No proven active file path is available. Check the hosting bootstrap before any manual edit.', 'wp-ai-bridge' ); ?></p>
				<?php endif; ?>
				<?php if ( $state['can_auto'] ) : ?>
					<p><button type="submit" form="wpai-native-sql-setup-form" class="button button-secondary" id="wpai-native-sql-setup-submit"><?php echo esc_html__( 'Add SQL server definition to wp-config.php', 'wp-ai-bridge' ); ?></button></p>
					<p class="description"><?php echo esc_html__( 'Opens its result in a new tab, keeping unsaved settings drafts here. The next fresh request must confirm activation.', 'wp-ai-bridge' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * No nested forms or settings submission: the submitter in Database uses
	 * the HTML form attribute, and a new tab preserves the original draft.
	 *
	 * @return void
	 */
	private function render_sql_setup_form() {
		$setup = new Native_SQL_Config_Setup();
		$state = $setup->inspect();
		?>
		<form id="wpai-native-sql-setup-form" method="post" target="_blank" rel="noopener" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Native_SQL_Config_Setup::ACTION ); ?>">
			<?php wp_nonce_field( $setup->nonce_action() ); ?>
			<input type="hidden" name="expected_digest" value="<?php echo esc_attr( $state['digest'] ); ?>">
			<input type="hidden" name="expected_path_hash" value="<?php echo esc_attr( $state['path_hash'] ); ?>">
			<input type="hidden" name="user" value="<?php echo esc_attr( (string) get_current_user_id() ); ?>">
			<input type="hidden" name="blog" value="<?php echo esc_attr( (string) get_current_blog_id() ); ?>">
			<input type="hidden" name="network" value="<?php echo esc_attr( (string) get_current_network_id() ); ?>">
		</form>
		<?php
	}

	/** @param string $title Task group. @param array $keys Grant keys. @return void */
	private function render_grant_group( $title, array $keys ) {
		$definitions = $this->settings->groups();
		$values      = $this->settings->all();
		?>
		<fieldset class="wpai-settings-group"><legend><h3><?php echo esc_html( $title ); ?></h3></legend>
		<?php foreach ( $keys as $key ) : ?>
			<?php
			$option = in_array( $key, Settings::DATABASE_GROUPS, true ) ? Settings::DATABASE_OPTION_NAME : ( in_array( $key, Settings::ADMINISTRATION_GROUPS, true ) ? Settings::ADMINISTRATION_OPTION_NAME : Settings::OPTION_NAME );
			$scalar = false;
			if ( Settings::GROUP_NATIVE_NETWORK_READ === $key || Settings::GROUP_NATIVE_NETWORK_WRITE === $key ) {
				$option = Settings::GROUP_NATIVE_NETWORK_READ === $key ? Native_Option_Access::NETWORK_OPTION_NAME : Native_Option_Access::NETWORK_WRITE_OPTION_NAME;
				$scalar = true;
			}
			$value   = $this->draft_option( $option, $scalar ? ( $values[ $key ] ?? 0 ) : $values );
			$enabled = $scalar ? '1' === $this->input_value( $value ) : is_array( $value ) && ! empty( $value[ $key ] );
			$name    = $scalar ? $option : $option . '[' . $key . ']';
			$allowed = $this->editable_option( $option ) && $this->settings->can_configure_group( $key );
			?>
			<div class="wpai-grant" data-option="<?php echo esc_attr( $option ); ?>">
				<?php
				if ( $allowed ) :
					?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0"><?php endif; ?>
				<label for="<?php echo esc_attr( 'wpai-grant-' . $key ); ?>"><input type="checkbox" id="<?php echo esc_attr( 'wpai-grant-' . $key ); ?>"
				<?php
				if ( $allowed ) :
					?>
					name="<?php echo esc_attr( $name ); ?>"<?php endif; ?> value="1" <?php checked( $enabled ); ?> <?php disabled( ! $allowed ); ?> aria-describedby="<?php echo esc_attr( 'wpai-grant-help-' . $key ); ?>"> <strong><?php echo esc_html( $definitions[ $key ]['label'] ); ?></strong></label>
				<div id="<?php echo esc_attr( 'wpai-grant-help-' . $key ); ?>"><p class="description"><?php echo esc_html( $definitions[ $key ]['description'] ); ?></p>
				<?php
				if ( ! $allowed ) :
					?>
					<p class="description"><?php echo $scalar ? esc_html__( 'Only a current Super Admin with network and site administration authority can change this consent. Network settings are available only on multisite.', 'wp-ai-bridge' ) : esc_html__( 'Changing this grant requires current administrator authority and, on multisite, actual Super Admin and network authority.', 'wp-ai-bridge' ); ?></p><?php endif; ?>
				<?php
				if ( ! empty( $definitions[ $key ]['warning'] ) ) :
					?>
					<p class="description wpai-grant-warning"><?php echo esc_html__( 'Enable only when you intend to expose this class of changes to authenticated MCP clients.', 'wp-ai-bridge' ); ?></p><?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
		</fieldset>
		<?php
	}
	/** @return void */
	private function render_scope_settings() {
		$owner   = new Administration_Configuration();
		$config  = $this->draft_option( Administration_Configuration::OPTION_NAME, $owner->all() );
		$config  = is_array( $config ) ? $config : $owner->all();
		$allowed = $this->editable_option( Administration_Configuration::OPTION_NAME );
		$roots   = is_array( $config['roots'] ?? null ) ? array_values( $config['roots'] ) : array();
		if ( ! $roots || '' !== ( $roots[ count( $roots ) - 1 ]['id'] ?? '' ) || '' !== ( $roots[ count( $roots ) - 1 ]['path'] ?? '' ) ) {
			$roots[] = array();
		}
		?>
		<fieldset class="wpai-settings-group" <?php disabled( ! $allowed ); ?> data-option="<?php echo esc_attr( Administration_Configuration::OPTION_NAME ); ?>">
			<legend><h3><?php echo esc_html__( 'Approved locations', 'wp-ai-bridge' ); ?></h3></legend>
			<p><?php echo esc_html__( 'Roots start empty. Use existing directories whose writers you trust. Removing a root revokes that scope when you save; it does not delete its files. A data root is not a PHP sandbox.', 'wp-ai-bridge' ); ?></p>
			<?php
			if ( ! $allowed ) :
				?>
				<p class="description"><?php echo esc_html__( 'Changing scopes requires current administrator authority and, on multisite, actual Super Admin and network authority.', 'wp-ai-bridge' ); ?></p><?php endif; ?>
			<input type="hidden" name="<?php echo esc_attr( Administration_Configuration::OPTION_NAME . '[roots]' ); ?>" value="">
			<div id="wpai-settings-roots">
				<?php foreach ( $roots as $index => $root ) : ?>
					<?php $this->render_scope_row( $index, is_array( $root ) ? $root : array() ); ?>
				<?php endforeach; ?>
			</div>
			<?php
			if ( $allowed ) :
				?>
				<p><button type="submit" name="wpai_add_scope" value="1" class="button" id="wpai-settings-add-scope" formnovalidate><?php echo esc_html__( 'Add directory scope', 'wp-ai-bridge' ); ?></button></p>
				<p class="description"><?php echo esc_html__( 'Adding a row does not save settings. An empty new row is ignored when you save.', 'wp-ai-bridge' ); ?></p>
			<?php endif; ?>
			<?php
			foreach ( array(
				'restore_schemas' => array( __( 'Empty restore database schemas', 'wp-ai-bridge' ), __( 'Exact database names, one per line. Restore additionally requires an empty destination and the existing database account’s native privileges. No new database connection is created.', 'wp-ai-bridge' ) ),
				'backup_tables'   => array( __( 'Additional approved backup tables', 'wp-ai-bridge' ), __( 'Exact current-database provider tables outside the installation prefix, one per line. Required dependencies must also be approved.', 'wp-ai-bridge' ) ),
			) as $key => $field ) :
				$value = $config[ $key ] ?? array();
				$value = is_array( $value ) ? implode( "\n", array_filter( $value, 'is_string' ) ) : $this->input_value( $value );
				?>
				<div class="wpai-setting-field">
					<label for="<?php echo esc_attr( 'wpai-scopes-' . $key ); ?>"><strong><?php echo esc_html( $field[0] ); ?></strong></label>
					<textarea class="large-text code" dir="ltr" rows="3" id="<?php echo esc_attr( 'wpai-scopes-' . $key ); ?>" name="<?php echo esc_attr( Administration_Configuration::OPTION_NAME . '[' . $key . ']' ); ?>" aria-describedby="<?php echo esc_attr( 'wpai-scopes-help-' . $key ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
					<p class="description" id="<?php echo esc_attr( 'wpai-scopes-help-' . $key ); ?>"><?php echo esc_html( $field[1] ); ?></p>
				</div>
			<?php endforeach; ?>
		</fieldset>
		<?php
		if ( $allowed ) :
			?>
			<template id="wpai-settings-root-template"><?php $this->render_scope_row( '__INDEX__', array() ); ?></template><?php endif; ?>
		<?php
	}

	/** @param int|string $index Unique row identity. @param array $root Saved or draft row. @return void */
	private function render_scope_row( $index, array $root ) {
		$base   = Administration_Configuration::OPTION_NAME . '[roots][' . $index . ']';
		$prefix = 'wpai-scope-' . $index . '-';
		$label  = $this->input_value( $root['label'] ?? '' );
		?>
		<fieldset class="wpai-scope-row">
			<legend><strong><?php echo esc_html( '' !== $label ? $label : __( 'Directory scope', 'wp-ai-bridge' ) ); ?></strong></legend>
			<div class="wpai-scope-fields">
			<?php
			foreach ( array(
				'id'    => __( 'Stable ID', 'wp-ai-bridge' ),
				'label' => __( 'Label', 'wp-ai-bridge' ),
				'path'  => __( 'Existing absolute directory', 'wp-ai-bridge' ),
			) as $key => $title ) :
				?>
				<p><label for="<?php echo esc_attr( $prefix . $key ); ?>"><?php echo esc_html( $title ); ?></label>
					<input type="text" class="<?php echo 'label' === $key ? 'regular-text' : 'large-text code'; ?>"
					<?php
					if ( 'label' !== $key ) :
						?>
						dir="ltr"<?php endif; ?> id="<?php echo esc_attr( $prefix . $key ); ?>" name="<?php echo esc_attr( $base . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $this->input_value( $root[ $key ] ?? '' ) ); ?>"
						<?php
						if ( 'id' === $key ) :
							?>
						pattern="[A-Za-z0-9][A-Za-z0-9_.-]{0,63}"<?php endif; ?>></p>
			<?php endforeach; ?>
			<p><label for="<?php echo esc_attr( $prefix . 'scope' ); ?>"><?php echo esc_html__( 'Authority scope', 'wp-ai-bridge' ); ?></label>
				<select id="<?php echo esc_attr( $prefix . 'scope' ); ?>" name="<?php echo esc_attr( $base . '[scope]' ); ?>">
					<option value="site" <?php selected( 'site', $root['scope'] ?? 'site' ); ?>><?php echo esc_html__( 'Current site', 'wp-ai-bridge' ); ?></option>
					<option value="network" <?php selected( 'network', $root['scope'] ?? 'site' ); ?>><?php echo esc_html__( 'Current network', 'wp-ai-bridge' ); ?></option>
				</select></p>
			<fieldset class="wpai-scope-choices"><legend><?php echo esc_html__( 'Purposes', 'wp-ai-bridge' ); ?></legend>
				<input type="hidden" name="<?php echo esc_attr( $base . '[purposes]' ); ?>" value="">
				<?php
				foreach ( array(
					'filesystem'     => __( 'File management', 'wp-ai-bridge' ),
					'logs'           => __( 'Log reading', 'wp-ai-bridge' ),
					'backup_export'  => __( 'Backup source', 'wp-ai-bridge' ),
					'backup_restore' => __( 'Restore destination', 'wp-ai-bridge' ),
				) as $purpose => $title ) :
					?>
					<label><input type="checkbox" name="<?php echo esc_attr( $base . '[purposes][]' ); ?>" value="<?php echo esc_attr( $purpose ); ?>" <?php checked( is_array( $root['purposes'] ?? null ) && in_array( $purpose, $root['purposes'], true ) ); ?>> <?php echo esc_html( $title ); ?></label>
				<?php endforeach; ?>
			</fieldset>
			<fieldset class="wpai-scope-choices"><legend><?php echo esc_html__( 'Allowed access', 'wp-ai-bridge' ); ?></legend>
				<?php
				foreach ( array(
					'read'        => __( 'Read', 'wp-ai-bridge' ),
					'write'       => __( 'Create and replace', 'wp-ai-bridge' ),
					'destructive' => __( 'Delete and move', 'wp-ai-bridge' ),
					'executable'  => __( 'Code and configuration', 'wp-ai-bridge' ),
				) as $flag => $title ) :
					?>
					<input type="hidden" name="<?php echo esc_attr( $base . '[' . $flag . ']' ); ?>" value="0">
					<label><input type="checkbox" name="<?php echo esc_attr( $base . '[' . $flag . ']' ); ?>" value="1" <?php checked( ! empty( $root[ $flag ] ) ); ?>> <?php echo esc_html( $title ); ?></label>
				<?php endforeach; ?>
			</fieldset>
			<p><input type="hidden" name="<?php echo esc_attr( $base . '[nonserved]' ); ?>" value="0"><label><input type="checkbox" name="<?php echo esc_attr( $base . '[nonserved]' ); ?>" value="1" <?php checked( ! empty( $root['nonserved'] ) ); ?>> <?php echo esc_html__( 'The restore destination is not served by this or another website.', 'wp-ai-bridge' ); ?></label></p>
			</div>
			<p class="wpai-scope-remove"><input type="hidden" name="<?php echo esc_attr( $base . '[remove]' ); ?>" value="0"><label><input type="checkbox" name="<?php echo esc_attr( $base . '[remove]' ); ?>" value="1" <?php checked( ! empty( $root['remove'] ) ); ?>> <span><?php echo esc_html__( 'Remove this scope on save', 'wp-ai-bridge' ); ?></span></label></p>
		</fieldset>
		<?php
	}

	/** @param mixed $value Submitted scalar. @return string Safe renderable input; escaping remains at output. */
	private function input_value( $value ) {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/** @param string $option Store. @param string|null $key Field or scalar. @param string $label Label. @param mixed $saved Stored value. @param int $min Lower bound. @param int $max Upper bound. @param string $description Help. @return void */
	private function render_limit_field( $option, $key, $label, $saved, $min, $max, $description ) {
		$values  = $this->draft_option( $option, $saved );
		$value   = null === $key ? $values : ( is_array( $values ) ? ( $values[ $key ] ?? '' ) : '' );
		$name    = null === $key ? $option : $option . '[' . $key . ']';
		$id      = 'wpai-limit-' . sanitize_key( $option . '-' . ( $key ?? 'value' ) );
		$allowed = $this->editable_option( $option );
		?>
		<div class="wpai-setting-field" data-option="<?php echo esc_attr( $option ); ?>">
			<label for="<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label>
			<input type="text" inputmode="numeric" pattern="[1-9][0-9]*" class="regular-text wpai-limit-input" dir="ltr" id="<?php echo esc_attr( $id ); ?>"
			<?php
			if ( $allowed ) :
				?>
				name="<?php echo esc_attr( $name ); ?>"<?php endif; ?> value="<?php echo esc_attr( $this->input_value( $value ) ); ?>" <?php disabled( ! $allowed ); ?> required aria-describedby="<?php echo esc_attr( $id . '-help' ); ?>">
			<div id="<?php echo esc_attr( $id . '-help' ); ?>"><p class="description"><?php echo esc_html( $description ); ?></p>
				<p class="description">
				<?php
				/* translators: 1: minimum setting, 2: maximum setting. */
				echo esc_html( sprintf( __( 'Allowed range: %1$s to %2$s.', 'wp-ai-bridge' ), (string) $min, (string) $max ) );
				?>
				</p></div>
		</div>
		<?php
	}

	/** @return void */
	private function render_capacity_settings() {
		$p_owner  = new Payload_Settings();
		$p        = $p_owner->all();
		$t_owner  = new Transfer_Settings();
		$t        = $t_owner->all();
		$t_max    = $t_owner->upper_bounds();
		$w_owner  = new Workspace_Limits();
		$w        = $w_owner->all();
		$usage    = $this->workspace->capacity_counts();
		$r_owner  = new Operation_Receipt_Limits();
		$receipts = Create_Claim_Store::count_domain( Create_Claim::DOMAIN_OPERATION );
		$o_owner  = new Operation_Content_Limits();
		$o        = $o_owner->all();
		$o_max    = $o_owner->upper_bounds();
		$rest     = new REST_Invocation_Input_Limits();
		$package  = new External_Package_Limits();
		$config   = ( new Administration_Configuration() )->all();
		?>
		<p><?php echo esc_html__( 'These limits control separate resources. Changing a limit never enables an ability or grants WordPress authority. No capacity is preallocated when idle.', 'wp-ai-bridge' ); ?></p>
		<p class="description"><?php echo esc_html__( 'Units: 1 KiB = 1,024 bytes; 1 MiB = 1,024 KiB; 128 KiB is about 131 KB.', 'wp-ai-bridge' ); ?></p>
		<fieldset class="wpai-settings-group"><legend><h3><?php echo esc_html__( 'Requests and responses', 'wp-ai-bridge' ); ?></h3></legend>
		<?php
		$this->render_limit_field( Transfer_Settings::OPTION_NAME, Transfer_Settings::REQUEST_KIB, __( 'Incoming MCP request budget (KiB)', 'wp-ai-bridge' ), $t, 1, $t_max[ Transfer_Settings::REQUEST_KIB ], __( 'Bounds one encoded inbound tool message, not the aggregate file. Larger WordPress, proxy or MCP client limits must be configured separately.', 'wp-ai-bridge' ) );
		$this->render_limit_field( Payload_Settings::OPTION_NAME, Payload_Settings::RESPONSE_KEY, __( 'MCP JSON response budget (KiB)', 'wp-ai-bridge' ), $p, Payload_Settings::MIN_RESPONSE_KIB, Payload_Settings::MAX_RESPONSE_KIB, __( 'Encoded result size, including Unicode JSON escaping. Default: 128 KiB. Older Gateways with a 64 KiB limit require a smaller value (such as 48 KiB) until upgraded.', 'wp-ai-bridge' ) );
		$this->render_limit_field( Payload_Settings::OPTION_NAME, Payload_Settings::WINDOW_KEY, __( 'Maximum UTF-8 text window (KiB)', 'wp-ai-bridge' ), $p, Payload_Settings::MIN_WINDOW_KIB, Payload_Settings::MAX_WINDOW_KIB, __( 'Maximum source bytes per requested text window. Default: 128 KiB. A response may return a shorter window to fit JSON encoding. The entire text remains readable using offsets and hashes.', 'wp-ai-bridge' ) );
		?>
		</fieldset>
		<fieldset class="wpai-settings-group"><legend><h3><?php echo esc_html__( 'Workspace storage and recovery', 'wp-ai-bridge' ); ?></h3></legend>
			<p><?php echo esc_html__( 'Record counts are independent of byte limits. Archived records count toward each per-site limit. Lowering a limit does not delete records, notes or receipts; it can block new creates or oversized replacements. Expired create receipts remain reserved and authorized retry readback remains available.', 'wp-ai-bridge' ); ?></p>
		<?php
		foreach ( array(
			Workspace_Limits::DOCUMENTS_KEY => array( __( 'Maximum Workspace documents', 'wp-ai-bridge' ), Workspace_Limits::MIN_DOCUMENTS, Workspace_Limits::MAX_DOCUMENTS ),
			Workspace_Limits::TASKS_KEY     => array( __( 'Maximum Workspace tasks', 'wp-ai-bridge' ), Workspace_Limits::MIN_TASKS, Workspace_Limits::MAX_TASKS ),
		) as $key => $field ) {
			/* translators: %s: current record count or unavailable. */
			$help = sprintf( __( 'Current record usage: %s. Increases need real WordPress database capacity.', 'wp-ai-bridge' ), is_wp_error( $usage ) ? __( 'Unavailable', 'wp-ai-bridge' ) : (string) $usage[ $key ] );
			$this->render_limit_field( Workspace_Limits::OPTION_NAME, $key, $field[0], $w, $field[1], $field[2], $help );
		}
		$this->render_limit_field( Payload_Settings::OPTION_NAME, Payload_Settings::DOCUMENT_KEY, __( 'Workspace document storage limit (KiB per document)', 'wp-ai-bridge' ), $p, Payload_Settings::MIN_DOCUMENT_KIB, Payload_Settings::MAX_DOCUMENT_KIB, __( 'Default: 512 KiB. This bounds one private Workspace document, not WordPress posts, MCP message size or file transport.', 'wp-ai-bridge' ) );
		$this->render_limit_field( Payload_Settings::OPTION_NAME, Payload_Settings::NOTES_KEY, __( 'Workspace task notes storage limit (KiB per task)', 'wp-ai-bridge' ), $p, Payload_Settings::MIN_NOTES_KIB, Payload_Settings::MAX_NOTES_KIB, __( 'Default: 128 KiB. Lowering a limit does not erase existing longer notes, but blocks oversized replacements.', 'wp-ai-bridge' ) );
		/* translators: %s: typed receipt count or unavailable. */
		$help = sprintf( __( 'Default: 2048. Current typed receipts: %s. This does not change Workspace document-key reservations. Larger settings need database capacity and may increase count-query time.', 'wp-ai-bridge' ), PHP_INT_MAX === $receipts ? __( 'Unavailable', 'wp-ai-bridge' ) : (string) $receipts );
		$this->render_limit_field( Operation_Receipt_Limits::OPTION_NAME, null, __( 'Maximum generic create receipts per site', 'wp-ai-bridge' ), $r_owner->limit(), Operation_Receipt_Limits::MIN_RECEIPTS, Operation_Receipt_Limits::MAX_RECEIPTS, $help );
		?>
		</fieldset>
		<fieldset class="wpai-settings-group"><legend><h3><?php echo esc_html__( 'File transfers and packages', 'wp-ai-bridge' ); ?></h3></legend>
			<p><?php echo esc_html__( 'Finite private transfer reservations are separate from MCP message sizes. WordPress, PHP, filesystem, client and Gateway constraints still apply; Bridge cannot change those limits.', 'wp-ai-bridge' ); ?></p>
		<?php
		foreach ( array(
			Transfer_Settings::CHUNK_KIB  => array( __( 'Maximum raw transfer chunk (KiB)', 'wp-ai-bridge' ), __( 'Chunk bytes are reduced automatically to fit the effective incoming MCP JSON budget. This is not a total file-size ceiling.', 'wp-ai-bridge' ) ),
			Transfer_Settings::FILE_MIB   => array( __( 'One staged transfer reservation (MiB)', 'wp-ai-bridge' ), __( 'Adjust for actual private disk capacity and workloads. No archive, document or file extension has a universal Bridge-side aggregate cap.', 'wp-ai-bridge' ) ),
			Transfer_Settings::ACTIVE_MIB => array( __( 'Aggregate live reservation budget (MiB)', 'wp-ai-bridge' ), __( 'Limits total outstanding private storage reservations per WordPress site independently of the per-file budget.', 'wp-ai-bridge' ) ),
			Transfer_Settings::SESSIONS   => array( __( 'Concurrent private transfer sessions', 'wp-ai-bridge' ), __( 'Maximum non-expired transfers; expired sessions are cleaned on subsequent admissions.', 'wp-ai-bridge' ) ),
			Transfer_Settings::LEASE_MIN  => array( __( 'Private transfer lease lifetime (minutes)', 'wp-ai-bridge' ), __( 'Retention is finite and separate from one HTTP request timeout. The host must preserve temporary storage across requests.', 'wp-ai-bridge' ) ),
		) as $key => $field ) {
			$this->render_limit_field( Transfer_Settings::OPTION_NAME, $key, $field[0], $t, 1, $t_max[ $key ], $field[1] );
		}
		$this->render_limit_field( Payload_Settings::OPTION_NAME, Payload_Settings::INLINE_FILE_KEY, __( 'Inline MCP file-read source ceiling (MiB)', 'wp-ai-bridge' ), $p, Payload_Settings::MIN_INLINE_FILE_MIB, Payload_Settings::max_inline_file_mib(), __( 'Fallback only: existing Media Library source accepted for hash-bound, byte-exact MCP Base64 windows. Default: 128 MiB. Per-response windows are bounded separately; source size is not the total file-transfer limit.', 'wp-ai-bridge' ) );
		$this->render_limit_field( Payload_Settings::OPTION_NAME, Payload_Settings::INLINE_MEDIA_KEY, __( 'Inline Base64 Media upload budget (MiB)', 'wp-ai-bridge' ), $p, Payload_Settings::MIN_INLINE_MEDIA_MIB, Payload_Settings::max_inline_media_mib(), __( 'Default: 20 MiB. Legacy one-shot upload remains bounded by PHP memory, WordPress upload limits, client and proxy limits. Use private chunked transfer for large files.', 'wp-ai-bridge' ) );
		/* translators: 1: WordPress upload bytes, 2: effective package bytes. */
		$help = sprintf( __( 'Default: 100 MiB. WordPress upload allowance: %1$s bytes; effective package budget: %2$s bytes. Only streamed external HTTPS packages are affected. Native install permissions, Code & Extensions and External Packages grants remain required. Host resources can reduce the allowance or cause timeouts.', 'wp-ai-bridge' ), (string) wp_max_upload_size(), (string) $package->effective_bytes() );
		$this->render_limit_field( External_Package_Limits::OPTION_NAME, null, __( 'Maximum external extension package (MiB)', 'wp-ai-bridge' ), $package->requested_mib(), External_Package_Limits::MIN_MIB, External_Package_Limits::max_mib(), $help );
		?>
		</fieldset>
		<fieldset class="wpai-settings-group"><legend><h3><?php echo esc_html__( 'Native operation processing', 'wp-ai-bridge' ); ?></h3></legend>
			<p><?php echo esc_html__( 'Independent budgets for content materialized in protected native operations. PHP memory, provider schemas, permissions and incoming MCP limits remain binding; these budgets do not create whole-file transfer paths.', 'wp-ai-bridge' ); ?></p>
		<?php
		foreach ( array(
			Operation_Content_Limits::SOURCE_MIB         => array( __( 'Installed plugin/theme source preimage and candidate (MiB)', 'wp-ai-bridge' ), __( 'Default: 2 MiB. One complete source file is held for preview, exact write and crash recovery. Increasing needs PHP memory, database and client capacity; Source Editing consent is still required.', 'wp-ai-bridge' ) ),
			Operation_Content_Limits::SETTING_VALUE_KIB  => array( __( 'Registered REST setting JSON value (KiB)', 'wp-ai-bridge' ), __( 'Default: 1024 KiB. Native WordPress settings authority, provider schemas and incoming MCP message limits still apply.', 'wp-ai-bridge' ) ),
			Operation_Content_Limits::SETTING_SCHEMA_KIB => array( __( 'Registered REST setting public schema (KiB)', 'wp-ai-bridge' ), __( 'Default: 256 KiB. Oversized schemas remain unavailable rather than being silently truncated or leaking provider defaults.', 'wp-ai-bridge' ) ),
			Operation_Content_Limits::CATALOG_KIB        => array( __( 'Public Ability catalog response (KiB)', 'wp-ai-bridge' ), __( 'Default: 1024 KiB. Contract inspection stays read-only. Use pagination and check the MCP response limit when a larger catalog does not fit.', 'wp-ai-bridge' ) ),
		) as $key => $field ) {
			$this->render_limit_field( Operation_Content_Limits::OPTION_NAME, $key, $field[0], $o, 1, $o_max[ $key ], $field[1] );
		}
		/* translators: 1: effective input bytes, 2: current incoming MCP allowance. */
		$help = sprintf( __( 'Default: 16 KiB. Effective input allowance: %1$s bytes; incoming MCP request allowance: %2$s bytes. This does not enable REST invocation or bypass sensitive route exclusions and native permissions. Host, client, memory and provider JSON validation remain binding.', 'wp-ai-bridge' ), (string) $rest->effective_bytes(), (string) $t_owner->bytes()['request'] );
		$this->render_limit_field( REST_Invocation_Input_Limits::OPTION_NAME, null, __( 'Maximum registered REST JSON input (KiB)', 'wp-ai-bridge' ), $rest->requested_kib(), REST_Invocation_Input_Limits::MIN_KIB, REST_Invocation_Input_Limits::max_kib(), $help );
		$this->render_limit_field( Administration_Configuration::OPTION_NAME, 'step_bytes', __( 'Work bytes per step', 'wp-ai-bridge' ), $config, 1, PHP_INT_MAX, __( 'A resumable processing budget, not a total file or backup-size ceiling. Actual PHP, disk and transfer policies still apply.', 'wp-ai-bridge' ) );
		$this->render_limit_field( Administration_Configuration::OPTION_NAME, 'step_seconds', __( 'Work seconds per step', 'wp-ai-bridge' ), $config, 1, PHP_INT_MAX, __( 'A resumable processing time budget, separate from total operation size and the host request timeout.', 'wp-ai-bridge' ) );
		?>
		</fieldset>
		<?php
	}
	/** @return void */
	private function render_workspace_operations() {
		?>
			<?php
			if ( isset( $_GET['wpai_notice'] ) && 'workspace-cleared' === $_GET['wpai_notice'] ) :
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Status display only.
				?>
				<div class="notice notice-success"><p><?php echo esc_html__( 'Workspace documents and tasks were cleared.', 'wp-ai-bridge' ); ?></p></div><?php endif; ?>
			<p><?php echo esc_html__( 'Workspace data is preserved when the plugin is deactivated or uninstalled. Export it at any time. Clear is the explicit deletion path and cannot be undone.', 'wp-ai-bridge' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline-block; margin-inline-end: 1em">
				<input type="hidden" name="action" value="wpai_workspace_export">
				<?php wp_nonce_field( 'wpai_workspace_export' ); ?>
				<?php submit_button( __( 'Export Workspace', 'wp-ai-bridge' ), 'secondary', 'submit', false ); ?>
			</form>
			<p class="description"><?php echo esc_html__( 'When settings edits are pending, Clear opens in a new tab so your draft stays here. Neither Workspace action saves settings.', 'wp-ai-bridge' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="wpai-workspace-clear" target="_blank" rel="noopener">
				<input type="hidden" name="action" value="wpai_workspace_clear">
				<?php wp_nonce_field( 'wpai_workspace_clear' ); ?>
				<label><input type="checkbox" name="confirm_clear" value="clear" required> <?php echo esc_html__( 'I understand that Clear Workspace permanently deletes all Workspace documents and tasks.', 'wp-ai-bridge' ); ?></label>
				<p class="description"><?php echo esc_html__( 'Clear also requires the Users & Destructive access group to be enabled. It does not delete normal WordPress content.', 'wp-ai-bridge' ); ?></p>
				<?php submit_button( __( 'Clear Workspace', 'wp-ai-bridge' ), 'delete', 'submit', false ); ?>
			</form>
		<?php
	}

	/**
	 * Sends a JSON export of current Workspace state.
	 *
	 * @return void
	 */
	public function handle_export() {
		$this->require_admin();
		check_admin_referer( 'wpai_workspace_export' );
		$snapshot = $this->workspace->export_snapshot();
		if ( is_wp_error( $snapshot ) ) {
			wp_die( esc_html( $snapshot->get_error_message() ) );
		}
		$filename = 'wp-ai-bridge-workspace-' . gmdate( 'Y-m-d-His' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo wp_json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download encoded by wp_json_encode().
		exit;
	}

	/**
	 * Handles the explicit destructive Workspace clear action.
	 *
	 * @return void
	 */
	public function handle_clear() {
		$this->require_admin();
		check_admin_referer( 'wpai_workspace_clear' );
		$confirmed = isset( $_POST['confirm_clear'] ) ? sanitize_key( wp_unslash( $_POST['confirm_clear'] ) ) : '';
		if ( 'clear' !== $confirmed ) {
			wp_die( esc_html__( 'Explicit Workspace clear confirmation is required.', 'wp-ai-bridge' ) );
		}
		if ( ! $this->settings->is_enabled( Settings::GROUP_USERS_DESTRUCTIVE ) ) {
			wp_die( esc_html__( 'Users & Destructive access must be enabled before clearing the Workspace.', 'wp-ai-bridge' ) );
		}

		$result = $this->workspace->clear();
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		$this->log->record( 'wp-ai-bridge/workspace-clear', 'workspace', 0, true, '' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '&wpai_notice=workspace-cleared' ) );
		exit;
	}

	/**
	 * Renders compact connection status on Dashboard.
	 *
	 * @return void
	 */
	private function render_connection_summary() {
		?>
		<h2><?php echo esc_html__( 'Connection', 'wp-ai-bridge' ); ?></h2>
		<?php
		$this->render_connection_diagnostics();
	}

	/**
	 * Reports only local facts on both dashboard and settings screens.
	 * It never probes external networks, OAuth clients, or Site Health itself.
	 *
	 * @return void
	 */
	private function render_connection_diagnostics() {
		$wordpress_supported = $this->environment->wordpress_supported();
		$abilities_available = $this->environment->abilities_api_available();
		$adapter_available   = $this->environment->mcp_adapter_available();
		$adapter_version     = $this->environment->mcp_adapter_version();
		$https_configured    = $this->oauth_server->is_https_ready(); // Only checks the configured URL scheme.
		?>
		<table class="widefat striped" style="max-width: 900px"><tbody>
			<tr><th scope="row"><?php echo esc_html__( 'WordPress', 'wp-ai-bridge' ); ?></th><td><?php echo esc_html( $this->environment->wordpress_version() ); ?>
				<?php
				if ( ! $wordpress_supported ) :
					?>
					— <?php echo esc_html__( 'WordPress 6.9 or newer is required.', 'wp-ai-bridge' ); ?><?php endif; ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'Abilities API', 'wp-ai-bridge' ); ?></th><td><?php echo $abilities_available ? esc_html__( 'Available', 'wp-ai-bridge' ) : esc_html__( 'Unavailable: check WordPress version and Abilities API loading.', 'wp-ai-bridge' ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'MCP Adapter', 'wp-ai-bridge' ); ?></th><td>
				<?php
				if ( $adapter_available ) {
					echo esc_html( '' !== $adapter_version ? sprintf( /* translators: %s: MCP Adapter version. */ __( 'Available (%s)', 'wp-ai-bridge' ), $adapter_version ) : __( 'Available (version not reported)', 'wp-ai-bridge' ) );
				} else {
					echo esc_html__( 'Unavailable: install or activate the official MCP Adapter.', 'wp-ai-bridge' );
				}
				?>
			</td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'MCP URL scheme', 'wp-ai-bridge' ); ?></th><td><?php echo $https_configured ? esc_html__( 'HTTPS configured; public reachability not verified', 'wp-ai-bridge' ) : esc_html__( 'HTTPS not configured for this MCP URL', 'wp-ai-bridge' ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'MCP endpoint', 'wp-ai-bridge' ); ?></th><td><code><?php echo esc_html( $this->oauth_server->mcp_endpoint_url() ); ?></code></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'OAuth protected-resource metadata', 'wp-ai-bridge' ); ?></th><td><code><?php echo esc_html( $this->oauth_server->protected_resource_metadata_url() ); ?></code></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'OAuth authorization-server metadata', 'wp-ai-bridge' ); ?></th><td><code><?php echo esc_html( $this->oauth_server->authorization_server_metadata_url() ); ?></code><p class="description"><?php echo esc_html__( 'Metadata URLs are generated locally; remote fetching and routing have not been checked.', 'wp-ai-bridge' ); ?></p></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'External client connection', 'wp-ai-bridge' ); ?></th><td><?php echo esc_html__( 'Not verified here: an authenticated client must confirm connection.', 'wp-ai-bridge' ); ?></td></tr>
		</tbody></table>
		<p><strong><?php echo esc_html__( 'Next diagnostic steps:', 'wp-ai-bridge' ); ?></strong></p>
		<ul class="ul-disc" style="max-width: 900px">
			<?php if ( ! $wordpress_supported || ! $abilities_available ) : ?>
				<li><?php echo esc_html__( 'Use supported WordPress and verify that the native Abilities API is loaded.', 'wp-ai-bridge' ); ?></li>
			<?php endif; ?>
			<?php if ( ! $adapter_available ) : ?>
				<li><a href="<?php echo esc_url( 'https://github.com/WordPress/mcp-adapter/releases/latest' ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Install or activate the compatible official MCP Adapter', 'wp-ai-bridge' ); ?></a></li>
			<?php endif; ?>
			<?php if ( ! $https_configured ) : ?>
				<li><?php echo esc_html__( 'Check WordPress Site URL, REST URL, and reverse-proxy HTTPS configuration.', 'wp-ai-bridge' ); ?></li>
			<?php endif; ?>
			<?php if ( current_user_can( 'view_site_health_checks' ) ) : ?>
				<li><a href="<?php echo esc_url( admin_url( 'site-health.php' ) ); ?>"><?php echo esc_html__( 'Open Tools → Site Health', 'wp-ai-bridge' ); ?></a> — <?php echo esc_html__( 'Run native HTTPS, REST, loopback, and Authorization-header diagnostics where available.', 'wp-ai-bridge' ); ?></li>
			<?php else : ?>
				<li><?php echo esc_html__( 'Site Health diagnostics cannot be verified from this account; ask an authorized administrator to check them if available.', 'wp-ai-bridge' ); ?></li>
			<?php endif; ?>
			<li><?php echo esc_html__( 'If remote discovery fails, inspect /.well-known/ routing, firewall, CDN/proxy rules, and client network access. A local HTTPS URL proves none of these.', 'wp-ai-bridge' ); ?></li>
			<li><?php echo esc_html__( 'After connecting, check the exact WordPress user capability and Bridge access group for denied operations; rescan stale tools. Diagnose Gateway routing separately from direct MCP.', 'wp-ai-bridge' ); ?></li>
		</ul>
		<?php
	}

	/**
	 * Displays an exact Workspace project reference or a truthful unbound marker.
	 *
	 * @param string $project_ref Project reference stored with the record.
	 * @return void
	 */
	private function render_project_id( $project_ref ) {
		if ( '' === (string) $project_ref ) {
			echo '<span class="description">' . esc_html__( 'Unbound', 'wp-ai-bridge' ) . '</span>';
			return;
		}
		echo '<code dir="ltr">' . esc_html( (string) $project_ref ) . '</code>';
	}

	/**
	 * Renders a compact task summary table.
	 *
	 * @param array<int,array<string,mixed>> $tasks Task summaries.
	 * @return void
	 */
	private function render_task_summary_table( $tasks ) {
		?>
		<table class="widefat striped" style="max-width: 900px"><thead><tr><th><?php echo esc_html__( 'Title', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Progress', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Review', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Delivery', 'wp-ai-bridge' ); ?></th></tr></thead><tbody>
		<?php
		if ( ! $tasks ) :
			?>
			<tr><td colspan="4"><?php echo esc_html__( 'No active Workspace tasks.', 'wp-ai-bridge' ); ?></td></tr><?php endif; ?>
		<?php
		foreach ( $tasks as $task ) :
			?>
			<tr><td><?php echo esc_html( $task['title'] ); ?></td><td><?php echo esc_html( $this->enum_label( $task['progress'] ) ); ?></td><td><?php echo esc_html( $this->enum_label( $task['review'] ) ); ?></td><td><?php echo esc_html( $this->enum_label( $task['delivery'] ) ); ?></td></tr><?php endforeach; ?>
		</tbody></table>
		<?php
	}

	/**
	 * Renders safe fixed enum options.
	 *
	 * @param array<int,string> $values   Values.
	 * @param string            $selected Selected value.
	 * @return void
	 */
	private function render_options( $values, $selected ) {
		foreach ( $values as $value ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $selected, $value, false ), esc_html( $this->enum_label( $value ) ) );
		}
	}

	/**
	 * Returns a localized label for a stable Workspace state token.
	 *
	 * @param string $value Stable state token.
	 * @return string
	 */
	private function enum_label( $value ) {
		$labels = array(
			'todo'              => __( 'To do', 'wp-ai-bridge' ),
			'in_progress'       => __( 'In progress', 'wp-ai-bridge' ),
			'blocked'           => __( 'Blocked', 'wp-ai-bridge' ),
			'done'              => __( 'Done', 'wp-ai-bridge' ),
			'not_required'      => __( 'Not required', 'wp-ai-bridge' ),
			'pending'           => __( 'Pending', 'wp-ai-bridge' ),
			'changes_requested' => __( 'Changes requested', 'wp-ai-bridge' ),
			'approved'          => __( 'Approved', 'wp-ai-bridge' ),
			'not_applicable'    => __( 'Not applicable', 'wp-ai-bridge' ),
			'draft_preview'     => __( 'Draft preview', 'wp-ai-bridge' ),
			'live'              => __( 'Live', 'wp-ai-bridge' ),
		);

		return isset( $labels[ $value ] ) ? $labels[ $value ] : (string) $value;
	}

	/**
	 * Separate current-network consent; ordinary site options.php never writes it.
	 *
	 * @return void
	 */
	private function render_native_network_read_form() {
		$allowed = Native_Option_Access::can_configure_network();
		$network = $allowed ? get_current_network_id() : 0;
		?>
		<h2><?php echo esc_html__( 'Sensitive Native Network Settings Read', 'wp-ai-bridge' ); ?></h2>
		<p><?php echo esc_html__( 'This separate consent belongs to the current network. Reading network settings also requires the current site settings read grant and actual Super Admin network authority. Ordinary site saves do not change this consent.', 'wp-ai-bridge' ); ?></p>
		<?php if ( $allowed ) : ?>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="wpai_native_network_read">
				<input type="hidden" name="network_id" value="<?php echo esc_attr( (string) $network ); ?>">
				<?php wp_nonce_field( 'wpai-native-network-read-' . $network ); ?>
				<label><input type="checkbox" name="enabled" value="1" <?php checked( Native_Option_Access::network_enabled() ); ?>> <?php echo esc_html__( 'Enable sensitive current-network settings reads and private exports', 'wp-ai-bridge' ); ?></label>
				<p class="description"><?php echo esc_html__( 'Native settings may contain credentials under any name. This grants no native settings writes.', 'wp-ai-bridge' ); ?></p>
				<?php submit_button( __( 'Save network settings read access', 'wp-ai-bridge' ) ); ?>
			</form>
		<?php else : ?>
			<label><input type="checkbox" disabled <?php checked( Native_Option_Access::network_enabled() ); ?>> <?php echo esc_html__( 'Enable sensitive current-network settings reads and private exports', 'wp-ai-bridge' ); ?></label>
			<p class="description"><?php echo esc_html__( 'Only a current Super Admin with network and site administration authority can change this network-owned consent. Network settings are available only on multisite.', 'wp-ai-bridge' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/** @return void Nonce-bound current-network consent handler. */
	public function handle_native_network_read() {
		try {
			Hook_Dispatch_Recovery::run(
				function () {
					$user    = get_current_user_id();
					$blog    = get_current_blog_id();
					$network = is_multisite() ? get_current_network_id() : 0;
					if ( ! Native_Option_Access::can_configure_network( $network ) ||
						'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ||
						! isset( $_POST['network_id'] ) || ! is_string( $_POST['network_id'] ) ||
						wp_unslash( $_POST['network_id'] ) !== (string) $network ) {
						wp_die( esc_html__( 'Current Super Admin network authority and an exact current-network request are required.', 'wp-ai-bridge' ), '', array( 'response' => 403 ) );
						return;
					}
					check_admin_referer( 'wpai-native-network-read-' . $network );
					if ( get_current_user_id() !== $user || get_current_blog_id() !== $blog ||
						get_current_network_id() !== $network || ! Native_Option_Access::can_configure_network( $network ) ||
						( isset( $_POST['enabled'] ) && ( ! is_string( $_POST['enabled'] ) || '1' !== wp_unslash( $_POST['enabled'] ) ) ) ) {
						wp_die( esc_html__( 'The native network settings consent request is invalid or its authority changed.', 'wp-ai-bridge' ), '', array( 'response' => 403 ) );
						return;
					}
					$value = isset( $_POST['enabled'] ) ? 1 : 0;
					update_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, $value );
					if ( get_current_user_id() !== $user || get_current_blog_id() !== $blog ||
						get_current_network_id() !== $network || ! Native_Option_Access::can_configure_network( $network ) ||
						( 1 === $value ) !== Native_Option_Access::network_enabled() ) {
						wp_die( esc_html__( 'The network settings consent result could not be verified. Inspect current network consent before trying again.', 'wp-ai-bridge' ), '', array( 'response' => 500 ) );
						return;
					}
					wp_safe_redirect( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) );
					exit;
				}
			);
		} catch ( \Throwable $throwable ) {
			wp_die( esc_html__( 'The network settings consent result could not be verified. Inspect current network consent before trying again.', 'wp-ai-bridge' ), '', array( 'response' => 500 ) );
		}
	}

	/** @return void Separate current-network write consent; read consent is untouched. */
	private function render_native_network_write_form() {
		$allowed = Native_Option_Access::can_configure_network();
		$network = $allowed ? get_current_network_id() : 0;
		?>
		<h2><?php echo esc_html__( 'Sensitive Native Network Settings Write', 'wp-ai-bridge' ); ?></h2>
		<p><?php echo esc_html__( 'This separate current-network consent permits native settings mutations through installed provider callbacks. Native site write access, Site Configuration and both sensitive read grants remain required; deletion also requires Users & Destructive.', 'wp-ai-bridge' ); ?></p>
		<?php if ( $allowed ) : ?>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="wpai_native_network_write">
				<input type="hidden" name="network_id" value="<?php echo esc_attr( (string) $network ); ?>">
				<?php wp_nonce_field( 'wpai-native-network-write-' . $network ); ?>
				<label><input type="checkbox" name="enabled" value="1" <?php checked( Native_Option_Access::network_write_enabled() ); ?>> <?php echo esc_html__( 'Enable sensitive current-network native settings writes', 'wp-ai-bridge' ); ?></label>
				<?php submit_button( __( 'Save network settings write access', 'wp-ai-bridge' ) ); ?>
			</form>
		<?php else : ?>
			<label><input type="checkbox" disabled <?php checked( Native_Option_Access::network_write_enabled() ); ?>> <?php echo esc_html__( 'Enable sensitive current-network native settings writes', 'wp-ai-bridge' ); ?></label>
			<p class="description"><?php echo esc_html__( 'Only a current Super Admin with network and site administration authority can change this network-owned write consent. Network settings require multisite.', 'wp-ai-bridge' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/** @return void Current-network-bound write-consent handler. */
	public function handle_native_network_write() {
		try {
			Hook_Dispatch_Recovery::run(
				function () {
					$user    = get_current_user_id();
					$blog    = get_current_blog_id();
					$network = is_multisite() ? get_current_network_id() : 0;
					if ( ! Native_Option_Access::can_configure_network( $network ) ||
						'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ||
						! isset( $_POST['network_id'] ) || ! is_string( $_POST['network_id'] ) ||
						wp_unslash( $_POST['network_id'] ) !== (string) $network ) {
						wp_die( esc_html__( 'Current Super Admin network authority and an exact current-network write-consent request are required.', 'wp-ai-bridge' ), '', array( 'response' => 403 ) );
						return;
					}
					check_admin_referer( 'wpai-native-network-write-' . $network );
					if ( get_current_user_id() !== $user || get_current_blog_id() !== $blog ||
						get_current_network_id() !== $network || ! Native_Option_Access::can_configure_network( $network ) ||
						( isset( $_POST['enabled'] ) && ( ! is_string( $_POST['enabled'] ) || '1' !== wp_unslash( $_POST['enabled'] ) ) ) ) {
						wp_die( esc_html__( 'The native network write-consent request is invalid or its authority changed.', 'wp-ai-bridge' ), '', array( 'response' => 403 ) );
						return;
					}
					$value = isset( $_POST['enabled'] ) ? 1 : 0;
					update_network_option( $network, Native_Option_Access::NETWORK_WRITE_OPTION_NAME, $value );
					if ( get_current_user_id() !== $user || get_current_blog_id() !== $blog ||
						get_current_network_id() !== $network || ! Native_Option_Access::can_configure_network( $network ) ||
						( 1 === $value ) !== Native_Option_Access::network_write_enabled() ) {
						wp_die( esc_html__( 'The network write-consent result could not be verified. Inspect current network consent before trying again.', 'wp-ai-bridge' ), '', array( 'response' => 500 ) );
						return;
					}
					wp_safe_redirect( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) );
					exit;
				}
			);
		} catch ( \Throwable $throwable ) {
			wp_die( esc_html__( 'The network write-consent result could not be verified. Inspect current network consent before trying again.', 'wp-ai-bridge' ), '', array( 'response' => 500 ) );
		}
	}

	/**
	 * Enforces administrator access to every Bridge admin screen/action.
	 *
	 * @return void
	 */
	private function require_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage WP AI Bridge settings.', 'wp-ai-bridge' ) );
		}
	}

	/**
	 * Render one native access-group control for its authoritative option.
	 *
	 * @param string $option_name Native setting option.
	 * @param string $key Group identifier.
	 * @param array  $group Translated group metadata.
	 * @param array  $values Current effective grants.
	 * @return void
	 */
	private function render_access_group_row( $option_name, $key, array $group, array $values ) {
		?>
		<tr><th scope="row"><?php echo esc_html( $group['label'] ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( $option_name . '[' . $key . ']' ); ?>" value="1" <?php checked( ! empty( $values[ $key ] ) ); ?> <?php disabled( ! $this->settings->can_configure_group( $key ) ); ?>> <?php echo esc_html__( 'Enabled', 'wp-ai-bridge' ); ?></label><p class="description"><?php echo esc_html( $group['description'] ); ?></p>
		<?php if ( ! $this->settings->can_configure_group( $key ) ) : ?>
			<p class="description"><?php echo esc_html__( 'Changing database access requires a current WordPress administrator and, on multisite, actual Super Admin and network authority.', 'wp-ai-bridge' ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $group['warning'] ) ) : ?>
			<p class="description"><strong><?php echo esc_html__( 'Warning:', 'wp-ai-bridge' ); ?></strong> <?php echo esc_html__( 'Enable only when you intend to expose this class of changes to authenticated MCP clients.', 'wp-ai-bridge' ); ?></p>
		<?php endif; ?>
		</td></tr>
		<?php
	}
}
