<?php
/**
 * WP AI Bridge administration area.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Admin;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Environment;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Payload_Settings;
use WP_AI_Bridge\Support\Transfer_Settings;
use WP_AI_Bridge\Support\Create_Claim;
use WP_AI_Bridge\Support\Create_Claim_Store;
use WP_AI_Bridge\Support\Operation_Receipt_Limits;
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
						<td dir="auto" style="text-align: left;"><?php echo esc_html( $document['title'] ); ?></td>
						<td style="text-align: left;"><?php $this->render_project_id( $document['project_ref'] ?? '' ); ?></td>
						<td style="text-align: left;"><code dir="ltr"><?php echo esc_html( $document['key'] ); ?></code></td>
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
				<h2 dir="auto" style="text-align: left; overflow-wrap: anywhere;"><?php echo esc_html( $view['title'] ); ?></h2>
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
					<tr><td dir="auto" style="text-align: left;"><?php echo esc_html( $task['title'] ); ?></td><td style="text-align: left;"><?php $this->render_project_id( $task['project_ref'] ?? '' ); ?></td><td><?php echo esc_html( $this->enum_label( $task['progress'] ) ); ?></td><td><?php echo esc_html( $this->enum_label( $task['review'] ) ); ?></td><td><?php echo esc_html( $this->enum_label( $task['delivery'] ) ); ?></td><td><?php echo esc_html( (string) $task['version'] ); ?></td><td><?php echo esc_html( $task['modified_gmt'] ); ?></td><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::TASKS_SLUG . '&task=' . (int) $task['id'] ) ); ?>"><?php echo esc_html__( 'Inspect', 'wp-ai-bridge' ); ?></a></td></tr>
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
				<h2 dir="auto" style="text-align: left; overflow-wrap: anywhere;"><?php echo esc_html( $view['title'] ); ?></h2>
				<p><strong><?php echo esc_html__( 'Project ID', 'wp-ai-bridge' ); ?></strong> <?php $this->render_project_id( $view['project_ref'] ?? '' ); ?></p>
				<p><strong><?php echo esc_html__( 'Goal:', 'wp-ai-bridge' ); ?></strong> <span dir="ltr" style="display: block; direction: ltr; text-align: left; overflow-wrap: anywhere; white-space: pre-wrap;"><?php echo esc_html( $view['goal'] ); ?></span></p>
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
			<p><?php echo esc_html__( 'Recent Bridge mutation metadata only. Workspace memory and request payloads are not stored in this activity log.', 'wp-ai-bridge' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Time (UTC)', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'User', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Ability', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Target', 'wp-ai-bridge' ); ?></th><th><?php echo esc_html__( 'Result', 'wp-ai-bridge' ); ?></th></tr></thead>
				<tbody>
				<?php
				if ( ! $entries ) :
					?>
					<tr><td colspan="5"><?php echo esc_html__( 'No recent Bridge activity.', 'wp-ai-bridge' ); ?></td></tr><?php endif; ?>
				<?php foreach ( $entries as $entry ) : ?>
					<tr><td><?php echo esc_html( $entry['timestamp'] ); ?></td><td><?php echo esc_html( (string) $entry['user_id'] ); ?></td><td><code><?php echo esc_html( $entry['ability'] ); ?></code></td><td><?php echo esc_html( $entry['target_type'] . ( $entry['target_id'] ? ':' . $entry['target_id'] : '' ) ); ?></td><td><?php echo esc_html( $entry['success'] ? __( 'Success', 'wp-ai-bridge' ) : __( 'Failed', 'wp-ai-bridge' ) ); ?>
					<?php
					if ( ! empty( $entry['error_code'] ) ) :
						?>
						— <code><?php echo esc_html( $entry['error_code'] ); ?></code><?php endif; ?></td></tr>
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
	public function render_settings() {
		$this->require_admin();
		$values = $this->settings->all();
		$notice = isset( $_GET['wpai_notice'] ) ? sanitize_key( wp_unslash( $_GET['wpai_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect status notice only.
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP AI Bridge Settings', 'wp-ai-bridge' ); ?></h1>
			<?php
			if ( 'workspace-cleared' === $notice ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Workspace documents and tasks were cleared.', 'wp-ai-bridge' ); ?></p></div><?php endif; ?>

			<h2><?php echo esc_html__( 'AI/MCP client connections', 'wp-ai-bridge' ); ?></h2>
			<p><?php echo esc_html__( 'These are local configuration observations, not a live client connection check. Configure a compatible authenticated OAuth/MCP client using the endpoint shown below.', 'wp-ai-bridge' ); ?></p>
			<?php $this->render_connection_diagnostics(); ?>
			<p><strong><?php echo esc_html__( 'Client setup:', 'wp-ai-bridge' ); ?></strong> <?php echo esc_html__( 'After resolving local prerequisites, configure the MCP URL in your client, choose OAuth, authenticate with WordPress, then rescan its tools. Successful OAuth does not grant WordPress or Bridge permissions.', 'wp-ai-bridge' ); ?></p>

			<h2><?php echo esc_html__( 'Access groups', 'wp-ai-bridge' ); ?></h2>
			<p><?php echo esc_html__( 'An enabled group only makes matching bridge abilities technically available. The connected WordPress user must also have the required WordPress capabilities. OAuth authorization does not bypass these checks.', 'wp-ai-bridge' ); ?></p>
			<form action="options.php" method="post">
				<?php settings_fields( Settings::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation"><tbody>
				<?php foreach ( $this->settings->groups() as $key => $group ) : ?>
					<tr><th scope="row"><?php echo esc_html( $group['label'] ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME . '[' . $key . ']' ); ?>" value="1" <?php checked( ! empty( $values[ $key ] ) ); ?>> <?php echo esc_html__( 'Enabled', 'wp-ai-bridge' ); ?></label><p class="description"><?php echo esc_html( $group['description'] ); ?></p>
					<?php
					if ( ! empty( $group['warning'] ) ) :
						?>
						<p class="description"><strong><?php echo esc_html__( 'Warning:', 'wp-ai-bridge' ); ?></strong> <?php echo esc_html__( 'Enable only when you intend to expose this class of changes to authenticated MCP clients.', 'wp-ai-bridge' ); ?></p><?php endif; ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<?php submit_button(); ?>
			</form>

			<?php
			// This is intentionally a separate native Settings API form: changing
			// a payload budget must never toggle an existing access group.
			$payload_settings = new Payload_Settings();
			$payload_limits   = $payload_settings->all();
			?>
			<h2><?php echo esc_html__( 'MCP response and text budgets', 'wp-ai-bridge' ); ?></h2>
			<p><?php echo esc_html__( 'These are per-request response and text-window budgets, not limits on the size of a WordPress document or transferred file. No capacity is preallocated when idle.', 'wp-ai-bridge' ); ?></p>
			<p><?php echo esc_html__( 'Workspace record storage limits below are separate quotas; they do not change the maximum MCP request size or create a file upload path.', 'wp-ai-bridge' ); ?></p>
			<p class="description"><?php echo esc_html__( 'Units: 1 KiB = 1,024 bytes; 1 MiB = 1,024 KiB; 128 KiB is about 131 KB.', 'wp-ai-bridge' ); ?></p>
			<form action="options.php" method="post">
				<?php settings_fields( Payload_Settings::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation"><tbody>
					<tr>
						<th scope="row"><label for="wpai-response-kib"><?php echo esc_html__( 'MCP JSON response budget (KiB)', 'wp-ai-bridge' ); ?></label></th>
						<td>
							<input type="number" class="small-text" id="wpai-response-kib" name="<?php echo esc_attr( Payload_Settings::OPTION_NAME . '[' . Payload_Settings::RESPONSE_KEY . ']' ); ?>" value="<?php echo esc_attr( (string) $payload_limits[ Payload_Settings::RESPONSE_KEY ] ); ?>" min="<?php echo esc_attr( (string) Payload_Settings::MIN_RESPONSE_KIB ); ?>" max="<?php echo esc_attr( (string) Payload_Settings::MAX_RESPONSE_KIB ); ?>" step="1" required>
							<p class="description"><?php echo esc_html__( 'Encoded result size, including Unicode JSON escaping. Default: 128 KiB; configurable from 16 to 1024 KiB. Older Gateways with a 64 KiB limit require a smaller value (such as 48 KiB) until upgraded.', 'wp-ai-bridge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpai-text-window-kib"><?php echo esc_html__( 'Maximum UTF-8 text window (KiB)', 'wp-ai-bridge' ); ?></label></th>
						<td>
							<input type="number" class="small-text" id="wpai-text-window-kib" name="<?php echo esc_attr( Payload_Settings::OPTION_NAME . '[' . Payload_Settings::WINDOW_KEY . ']' ); ?>" value="<?php echo esc_attr( (string) $payload_limits[ Payload_Settings::WINDOW_KEY ] ); ?>" min="<?php echo esc_attr( (string) Payload_Settings::MIN_WINDOW_KIB ); ?>" max="<?php echo esc_attr( (string) Payload_Settings::MAX_WINDOW_KIB ); ?>" step="1" required>
							<p class="description"><?php echo esc_html__( 'Maximum source bytes per requested text window. Default: 128 KiB; configurable from 4 to 512 KiB. A response may return a shorter window to fit JSON encoding. The entire text remains readable using offsets and hashes.', 'wp-ai-bridge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpai-document-kib"><?php echo esc_html__( 'Workspace document storage limit (KiB per document)', 'wp-ai-bridge' ); ?></label></th>
						<td>
							<input type="number" class="small-text" id="wpai-document-kib" name="<?php echo esc_attr( Payload_Settings::OPTION_NAME . '[' . Payload_Settings::DOCUMENT_KEY . ']' ); ?>" value="<?php echo esc_attr( (string) $payload_limits[ Payload_Settings::DOCUMENT_KEY ] ); ?>" min="<?php echo esc_attr( (string) Payload_Settings::MIN_DOCUMENT_KIB ); ?>" max="<?php echo esc_attr( (string) Payload_Settings::MAX_DOCUMENT_KIB ); ?>" step="1" required>
							<p class="description"><?php echo esc_html__( 'Default: 512 KiB; configurable from 64 to 1024 KiB. This bounds one private Workspace document, not WordPress posts or file transport.', 'wp-ai-bridge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpai-notes-kib"><?php echo esc_html__( 'Workspace task notes storage limit (KiB per task)', 'wp-ai-bridge' ); ?></label></th>
						<td>
							<input type="number" class="small-text" id="wpai-notes-kib" name="<?php echo esc_attr( Payload_Settings::OPTION_NAME . '[' . Payload_Settings::NOTES_KEY . ']' ); ?>" value="<?php echo esc_attr( (string) $payload_limits[ Payload_Settings::NOTES_KEY ] ); ?>" min="<?php echo esc_attr( (string) Payload_Settings::MIN_NOTES_KIB ); ?>" max="<?php echo esc_attr( (string) Payload_Settings::MAX_NOTES_KIB ); ?>" step="1" required>
							<p class="description"><?php echo esc_html__( 'Default: 128 KiB; configurable from 16 to 256 KiB. Lowering a limit does not erase existing longer notes, but blocks oversized replacements.', 'wp-ai-bridge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpai-inline-file-mib"><?php echo esc_html__( 'Inline MCP file-read source ceiling (MiB)', 'wp-ai-bridge' ); ?></label></th>
						<td>
							<input type="number" class="small-text" id="wpai-inline-file-mib" name="<?php echo esc_attr( Payload_Settings::OPTION_NAME . '[' . Payload_Settings::INLINE_FILE_KEY . ']' ); ?>" value="<?php echo esc_attr( (string) $payload_limits[ Payload_Settings::INLINE_FILE_KEY ] ); ?>" min="<?php echo esc_attr( (string) Payload_Settings::MIN_INLINE_FILE_MIB ); ?>" max="<?php echo esc_attr( (string) Payload_Settings::max_inline_file_mib() ); ?>" step="1" required>
							<p class="description"><?php echo esc_html__( 'Fallback only: maximum existing Media Library source accepted for hash-bound, byte-exact MCP Base64 windows. Default 128 MiB; administrator-adjustable up to the effective PHP numeric maximum. The per-response budget controls each window separately; source size never becomes the total file-transfer limit.', 'wp-ai-bridge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpai-inline-media-mib"><?php echo esc_html__( 'Inline Base64 Media upload budget (MiB)', 'wp-ai-bridge' ); ?></label></th>
						<td>
							<input type="number" class="small-text" id="wpai-inline-media-mib" name="<?php echo esc_attr( Payload_Settings::OPTION_NAME . '[' . Payload_Settings::INLINE_MEDIA_KEY . ']' ); ?>" value="<?php echo esc_attr( (string) $payload_limits[ Payload_Settings::INLINE_MEDIA_KEY ] ); ?>" min="<?php echo esc_attr( (string) Payload_Settings::MIN_INLINE_MEDIA_MIB ); ?>" max="<?php echo esc_attr( (string) Payload_Settings::max_inline_media_mib() ); ?>" step="1" required>
							<p class="description"><?php echo esc_html__( 'Default 20 MiB; administrator maximum is derived from current PHP memory rather than a fixed Bridge-side file cap. This legacy one-shot Base64 Media upload also remains bounded by WordPress upload_max_filesize/post_max_size, client request and proxy limits; use private chunked transfer for large files.', 'wp-ai-bridge' ); ?></p>
						</td>
					</tr>
				</tbody></table>
				<p class="description"><?php echo esc_html__( 'Bridge cannot change MCP client, Gateway, proxy, PHP or hosting limits. Increase those separately where supported; large private files still require an authenticated binary transport.', 'wp-ai-bridge' ); ?></p>
				<?php submit_button( __( 'Save response and text budgets', 'wp-ai-bridge' ) ); ?>
			</form>


			<?php
			// Workspace record-count policy is separate from MIME/byte budgets,
			// WordPress permissions and irreversible cleanup.
			$workspace_limits = new Workspace_Limits();
			$workspace_values = $workspace_limits->all();
			$workspace_usage  = $this->workspace->capacity_counts();
			?>
			<h2><?php echo esc_html__( 'Workspace document and task capacity', 'wp-ai-bridge' ); ?></h2>
			<p><?php echo esc_html__( 'Record counts are independent of per-document KiB size and MCP JSON budgets. Archived records remain preserved and count toward each per-site limit. Lowering a limit never removes existing records; it only blocks new creates once usage meets the limit.', 'wp-ai-bridge' ); ?></p>
			<form action="options.php" method="post">
				<?php settings_fields( Workspace_Limits::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation"><tbody>
					<?php
					foreach ( array(
						Workspace_Limits::DOCUMENTS_KEY => array( __( 'Maximum Workspace documents', 'wp-ai-bridge' ), Workspace_Limits::MIN_DOCUMENTS, Workspace_Limits::MAX_DOCUMENTS ),
						Workspace_Limits::TASKS_KEY     => array( __( 'Maximum Workspace tasks', 'wp-ai-bridge' ), Workspace_Limits::MIN_TASKS, Workspace_Limits::MAX_TASKS ),
					) as $key => $field ) :
						?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( 'wpai-workspace-count-' . $key ); ?>"><?php echo esc_html( $field[0] ); ?></label></th>
							<td>
								<input type="number" class="small-text" id="<?php echo esc_attr( 'wpai-workspace-count-' . $key ); ?>" name="<?php echo esc_attr( Workspace_Limits::OPTION_NAME . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( (string) $workspace_values[ $key ] ); ?>" min="<?php echo esc_attr( (string) $field[1] ); ?>" max="<?php echo esc_attr( (string) $field[2] ); ?>" step="1" required>
								<p class="description">
									<?php
									/* translators: 1: current Workspace records, 2: minimum count setting, 3: maximum count setting. */
									echo esc_html( sprintf( __( 'Current record usage: %1$s; allowed range: %2$s to %3$s. Increases need real WordPress database capacity.', 'wp-ai-bridge' ), is_wp_error( $workspace_usage ) ? __( 'Unavailable', 'wp-ai-bridge' ) : (string) $workspace_usage[ $key ], (string) $field[1], (string) $field[2] ) );
									?>
								</p>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody></table>
				<?php submit_button( __( 'Save Workspace record capacity', 'wp-ai-bridge' ) ); ?>
			</form>

			<?php
			// Generic keyed create receipts have a separate admission domain from
			// canonical Workspace keys and are never removed by capacity changes.
			$operation_limits = new Operation_Receipt_Limits();
			$operation_usage  = Create_Claim_Store::count_domain( Create_Claim::DOMAIN_OPERATION );
			?>
			<h2><?php echo esc_html__( 'Keyed create recovery capacity', 'wp-ai-bridge' ); ?></h2>
			<p><?php echo esc_html__( 'This setting limits the number of new durable generic operation_id receipts on this WordPress site. It does not change Workspace document-key reservations or access permissions. Old receipts stay reserved, including after expiry; lowering the setting never deletes receipts or prevents authorized retry readback.', 'wp-ai-bridge' ); ?></p>
			<form action="options.php" method="post">
				<?php settings_fields( Operation_Receipt_Limits::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation"><tbody><tr>
					<th scope="row"><label for="wpai-operation-receipt-capacity"><?php echo esc_html__( 'Maximum generic create receipts per site', 'wp-ai-bridge' ); ?></label></th>
					<td>
						<input type="number" class="regular-text" id="wpai-operation-receipt-capacity" name="<?php echo esc_attr( Operation_Receipt_Limits::OPTION_NAME ); ?>" value="<?php echo esc_attr( (string) $operation_limits->limit() ); ?>" min="<?php echo esc_attr( (string) Operation_Receipt_Limits::MIN_RECEIPTS ); ?>" max="<?php echo esc_attr( (string) Operation_Receipt_Limits::MAX_RECEIPTS ); ?>" step="1" required>
						<p class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: current typed receipts or unavailable, 2: minimum allowed, 3: maximum allowed. */
									__( 'Current typed receipts: %1$s; allowed range: %2$s to %3$s. Default: 2048. Larger settings need sufficient database capacity and may increase count-query time.', 'wp-ai-bridge' ),
									PHP_INT_MAX === $operation_usage ? __( 'Unavailable', 'wp-ai-bridge' ) : (string) $operation_usage,
									(string) Operation_Receipt_Limits::MIN_RECEIPTS,
									(string) Operation_Receipt_Limits::MAX_RECEIPTS
								)
							);
							?>
						</p>
					</td>
				</tr></tbody></table>
				<?php submit_button( __( 'Save keyed create recovery capacity', 'wp-ai-bridge' ) ); ?>
			</form>

			<?php
			// Separate native Settings API option: transfer capacity must not
			// alter either WordPress authority groups or legacy inline quotas.
			$transfer_settings = new Transfer_Settings();
			$transfer_values   = $transfer_settings->all();
			$transfer_maxima   = $transfer_settings->upper_bounds();
			$transfer_fields   = array(
				Transfer_Settings::REQUEST_KIB => array(
					__( 'Incoming MCP request budget (KiB)', 'wp-ai-bridge' ),
					__( 'Bounds one encoded inbound tool message, not the aggregate file. Larger WordPress, proxy or MCP client limits must be configured separately.', 'wp-ai-bridge' ),
				),
				Transfer_Settings::CHUNK_KIB   => array(
					__( 'Maximum raw transfer chunk (KiB)', 'wp-ai-bridge' ),
					__( 'Chunk bytes are also reduced automatically to fit the effective incoming MCP JSON budget. This is not a total file-size ceiling.', 'wp-ai-bridge' ),
				),
				Transfer_Settings::FILE_MIB    => array(
					__( 'One staged transfer reservation (MiB)', 'wp-ai-bridge' ),
					__( 'Adjust for actual private disk capacity and workloads. No archive, document or file extension has a universal Bridge-side aggregate cap.', 'wp-ai-bridge' ),
				),
				Transfer_Settings::ACTIVE_MIB  => array(
					__( 'Aggregate live reservation budget (MiB)', 'wp-ai-bridge' ),
					__( 'Limits total outstanding private storage reservations per WordPress site independently of the per-file budget.', 'wp-ai-bridge' ),
				),
				Transfer_Settings::SESSIONS    => array(
					__( 'Concurrent private transfer sessions', 'wp-ai-bridge' ),
					__( 'Maximum non-expired transfers; expired sessions are cleaned on subsequent admissions.', 'wp-ai-bridge' ),
				),
				Transfer_Settings::LEASE_MIN   => array(
					__( 'Private transfer lease lifetime (minutes)', 'wp-ai-bridge' ),
					__( 'Retention is finite and separate from one HTTP request timeout. The host must preserve temporary storage across requests.', 'wp-ai-bridge' ),
				),
			);
			?>
			<h2><?php echo esc_html__( 'Private file transfer capacity', 'wp-ai-bridge' ); ?></h2>
			<p><?php echo esc_html__( 'Adjust these values without changing plugin source. They reserve finite, revocable private transfer resources; WordPress, PHP, filesystem, client and Gateway constraints still apply.', 'wp-ai-bridge' ); ?></p>
			<form action="options.php" method="post">
				<?php settings_fields( Transfer_Settings::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation"><tbody>
					<?php foreach ( $transfer_fields as $key => $field ) : ?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( 'wpai-transfer-' . $key ); ?>"><?php echo esc_html( $field[0] ); ?></label></th>
							<td>
								<input type="text" inputmode="numeric" pattern="[1-9][0-9]*" class="regular-text" id="<?php echo esc_attr( 'wpai-transfer-' . $key ); ?>" name="<?php echo esc_attr( Transfer_Settings::OPTION_NAME . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( (string) $transfer_values[ $key ] ); ?>" required>
								<p class="description"><?php echo esc_html( $field[1] ); ?></p>
								<p class="description"><?php echo esc_html__( 'Current PHP-derived maximum:', 'wp-ai-bridge' ); ?> <?php echo esc_html( (string) $transfer_maxima[ $key ] ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody></table>
				<?php submit_button( __( 'Save private transfer capacity', 'wp-ai-bridge' ) ); ?>
			</form>

			<h2><?php echo esc_html__( 'Workspace data lifecycle', 'wp-ai-bridge' ); ?></h2>
			<p><?php echo esc_html__( 'Workspace data is preserved when the plugin is deactivated or uninstalled. Export it at any time. Clear is the explicit deletion path and cannot be undone.', 'wp-ai-bridge' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline-block; margin-inline-end: 1em">
				<input type="hidden" name="action" value="wpai_workspace_export">
				<?php wp_nonce_field( 'wpai_workspace_export' ); ?>
				<?php submit_button( __( 'Export Workspace', 'wp-ai-bridge' ), 'secondary', 'submit', false ); ?>
			</form>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin-top: 1em">
				<input type="hidden" name="action" value="wpai_workspace_clear">
				<?php wp_nonce_field( 'wpai_workspace_clear' ); ?>
				<label><input type="checkbox" name="confirm_clear" value="clear" required> <?php echo esc_html__( 'I understand that Clear Workspace permanently deletes all Workspace documents and tasks.', 'wp-ai-bridge' ); ?></label>
				<p class="description"><?php echo esc_html__( 'Clear also requires the Users & Destructive access group to be enabled. It does not delete normal WordPress content.', 'wp-ai-bridge' ); ?></p>
				<?php submit_button( __( 'Clear Workspace', 'wp-ai-bridge' ), 'delete', 'submit', false ); ?>
			</form>
		</div>
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
			<tr><td dir="auto" style="text-align: left;"><?php echo esc_html( $task['title'] ); ?></td><td><?php echo esc_html( $this->enum_label( $task['progress'] ) ); ?></td><td><?php echo esc_html( $this->enum_label( $task['review'] ) ); ?></td><td><?php echo esc_html( $this->enum_label( $task['delivery'] ) ); ?></td></tr><?php endforeach; ?>
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
	 * Enforces administrator access to every Bridge admin screen/action.
	 *
	 * @return void
	 */
	private function require_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage WP AI Bridge settings.', 'wp-ai-bridge' ) );
		}
	}
}
