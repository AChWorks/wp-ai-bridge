<?php
/**
 * Native WordPress controls for explicit administration delegation and roots.
 *
 * @package WP_AI_Bridge
 */
namespace WP_AI_Bridge\Admin;

use WP_AI_Bridge\Support\Administration_Configuration;
use WP_AI_Bridge\Support\Settings;

/** Keeps operator configuration separate from ordinary option saves. */
final class Administration_Page {
	const SLUG = 'wp-ai-bridge-administration';
	/** @var Settings */
	private $settings;
	/** @param Settings $settings Existing grouped grant owner. */
	public function __construct( Settings $settings ) {
		$this->settings = $settings; }

	/** @return void */
	public function register_menu() {
		add_submenu_page( Settings_Page::PAGE_SLUG, __( 'Advanced administration', 'wp-ai-bridge' ), __( 'Administration', 'wp-ai-bridge' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	/** @return void */
	public function render() {
		$configuration = new Administration_Configuration();
		if ( ! $configuration->can_configure() ) {
			wp_die( esc_html__( 'Administration delegation requires current administrator and network authority.', 'wp-ai-bridge' ), esc_html__( 'Administration', 'wp-ai-bridge' ), array( 'response' => 403 ) );
			return;
		}
		wp_enqueue_script( 'wp-ai-bridge-administration', plugins_url( 'src/Admin/administration-settings.js', WP_AI_BRIDGE_FILE ), array(), WP_AI_BRIDGE_VERSION, true );
		$config = $configuration->all();
		$values = $this->settings->all();
		$groups = $this->settings->groups();
		$notice = isset( $_GET['wpai_notice'] ) ? sanitize_key( wp_unslash( $_GET['wpai_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice.
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Advanced administration', 'wp-ai-bridge' ); ?></h1>
			<?php
			if ( 'administration-saved' === $notice ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Administration scopes saved.', 'wp-ai-bridge' ); ?></p></div><?php endif; ?>
			<?php settings_errors(); ?>
			<p><?php echo esc_html__( 'Grant only the operations a connected WordPress administrator needs, then select the exact file and restore scopes below. Existing client approval and native capabilities remain required.', 'wp-ai-bridge' ); ?></p>
			<h2><?php echo esc_html__( 'Delegated operations', 'wp-ai-bridge' ); ?></h2>
			<form action="options.php" method="post">
				<?php settings_fields( Settings::ADMINISTRATION_OPTION_GROUP ); ?>
				<table class="form-table" role="presentation"><tbody>
				<?php
				foreach ( Settings::ADMINISTRATION_GROUPS as $key ) :
					$group = $groups[ $key ];
					?>
					<tr><th scope="row"><?php echo esc_html( $group['label'] ); ?></th><td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::ADMINISTRATION_OPTION_NAME . '[' . $key . ']' ); ?>" value="1" <?php checked( 1, $values[ $key ] ?? 0 ); ?>> <?php echo esc_html__( 'Enable this delegation', 'wp-ai-bridge' ); ?></label>
						<p class="description"><?php echo esc_html( $group['description'] ); ?></p>
					</td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<?php submit_button( __( 'Save administration access', 'wp-ai-bridge' ), 'primary', 'wpai_administration_access_submit' ); ?>
			</form>

			<h2><?php echo esc_html__( 'Native database authority', 'wp-ai-bridge' ); ?></h2>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Settings_Page::SETTINGS_SLUG ) ); ?>"><?php echo esc_html__( 'Configure database inspection, reading, writing, deletion, schema and SQL grants in Settings.', 'wp-ai-bridge' ); ?></a></p>
			<p><?php echo esc_html( defined( 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL' ) && true === WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL ? __( 'Server policy enables native-account SQL. Its native privileges can affect Bridge controls and other accessible schemas.', 'wp-ai-bridge' ) : __( 'Native-account SQL is disabled by server policy. The hosting operator must explicitly enable WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL before the separate SQL grant can be used.', 'wp-ai-bridge' ) ); ?></p>

			<h2><?php echo esc_html__( 'Approved file and restore scopes', 'wp-ai-bridge' ); ?></h2>
			<p><?php echo esc_html__( 'Roots start empty. Use existing directories whose writers you trust. Removing a root revokes that scope; it does not delete its files. A data root is not a PHP sandbox.', 'wp-ai-bridge' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" id="wpai-administration-scopes">
				<input type="hidden" name="action" value="wpai_administration_configuration">
				<input type="hidden" name="expected_revision" value="<?php echo esc_attr( $config['revision'] ); ?>">
				<input type="hidden" name="blog_id" value="<?php echo esc_attr( (string) $config['blog'] ); ?>">
				<input type="hidden" name="network_id" value="<?php echo esc_attr( (string) $config['network'] ); ?>">
				<?php wp_nonce_field( 'wpai-administration-configuration-' . $config['blog'] . '-' . $config['network'] ); ?>
				<div id="wpai-administration-roots">
				<?php
				$index = 0;
				foreach ( $config['roots'] as $root ) {
					$this->root_fields( $index++, $root ); }
				?>
				</div>
				<p><button type="button" class="button" id="wpai-add-root"><?php echo esc_html__( 'Add directory scope', 'wp-ai-bridge' ); ?></button></p>
				<template id="wpai-administration-root-template"><?php $this->root_fields( '__INDEX__', array() ); ?></template>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><label for="wpai-restore-schemas"><?php echo esc_html__( 'Empty restore database schemas', 'wp-ai-bridge' ); ?></label></th><td>
						<textarea class="large-text code" dir="ltr" rows="3" id="wpai-restore-schemas" name="restore_schemas"><?php echo esc_textarea( implode( "\n", $config['restore_schemas'] ) ); ?></textarea>
						<p class="description"><?php echo esc_html__( 'Exact database names, one per line. Restore additionally requires an empty destination and the existing database account’s native privileges. No new database connection is created.', 'wp-ai-bridge' ); ?></p>
					</td></tr>
					<tr><th scope="row"><label for="wpai-backup-tables"><?php echo esc_html__( 'Additional approved backup tables', 'wp-ai-bridge' ); ?></label></th><td>
						<textarea class="large-text code" dir="ltr" rows="3" id="wpai-backup-tables" name="backup_tables"><?php echo esc_textarea( implode( "\n", $config['backup_tables'] ) ); ?></textarea>
						<p class="description"><?php echo esc_html__( 'Exact current-database provider tables outside the installation prefix, one per line. Required dependencies must also be approved.', 'wp-ai-bridge' ); ?></p>
					</td></tr>
					<tr><th scope="row"><label for="wpai-step-bytes"><?php echo esc_html__( 'Work bytes per step', 'wp-ai-bridge' ); ?></label></th><td>
						<input type="number" min="1" step="1" class="regular-text" id="wpai-step-bytes" name="step_bytes" value="<?php echo esc_attr( (string) $config['step_bytes'] ); ?>" required>
						<p class="description"><?php echo esc_html__( 'A resumable processing budget, not a total file or backup-size ceiling. Actual PHP, disk and transfer policies still apply.', 'wp-ai-bridge' ); ?></p>
					</td></tr>
					<tr><th scope="row"><label for="wpai-step-seconds"><?php echo esc_html__( 'Work seconds per step', 'wp-ai-bridge' ); ?></label></th><td>
						<input type="number" min="1" step="1" class="small-text" id="wpai-step-seconds" name="step_seconds" value="<?php echo esc_attr( (string) $config['step_seconds'] ); ?>" required>
					</td></tr>
				</tbody></table>
				<input type="hidden" name="form_complete" value="1">
				<?php submit_button( __( 'Save approved scopes', 'wp-ai-bridge' ), 'primary', 'wpai_administration_scopes_submit' ); ?>
			</form>
		</div>
		<?php
	}

	/** @param int|string $index Stable form field index. @param array $root Configured record or empty. @return void */
	private function root_fields( $index, array $root ) {
		$base   = 'roots[' . $index . ']';
		$prefix = 'wpai-root-' . $index . '-';
		?>
		<fieldset class="wpai-administration-root" style="border:1px solid #c3c4c7;padding:12px;margin:12px 0;max-width:960px">
			<legend><strong><?php echo esc_html__( 'Directory scope', 'wp-ai-bridge' ); ?></strong></legend>
			<p><label for="<?php echo esc_attr( $prefix . 'id' ); ?>"><?php echo esc_html__( 'Stable ID', 'wp-ai-bridge' ); ?></label><br><input class="regular-text code" dir="ltr" id="<?php echo esc_attr( $prefix . 'id' ); ?>" name="<?php echo esc_attr( $base . '[id]' ); ?>" value="<?php echo esc_attr( $root['id'] ?? '' ); ?>" pattern="[A-Za-z0-9][A-Za-z0-9_.-]{0,63}" required></p>
			<p><label for="<?php echo esc_attr( $prefix . 'label' ); ?>"><?php echo esc_html__( 'Label', 'wp-ai-bridge' ); ?></label><br><input class="regular-text" id="<?php echo esc_attr( $prefix . 'label' ); ?>" name="<?php echo esc_attr( $base . '[label]' ); ?>" value="<?php echo esc_attr( $root['label'] ?? '' ); ?>"></p>
			<p><label for="<?php echo esc_attr( $prefix . 'path' ); ?>"><?php echo esc_html__( 'Existing absolute directory', 'wp-ai-bridge' ); ?></label><br><input class="large-text code" dir="ltr" id="<?php echo esc_attr( $prefix . 'path' ); ?>" name="<?php echo esc_attr( $base . '[path]' ); ?>" value="<?php echo esc_attr( $root['path'] ?? '' ); ?>" required></p>
			<p><label for="<?php echo esc_attr( $prefix . 'scope' ); ?>"><?php echo esc_html__( 'Authority scope', 'wp-ai-bridge' ); ?></label> <select id="<?php echo esc_attr( $prefix . 'scope' ); ?>" name="<?php echo esc_attr( $base . '[scope]' ); ?>"><option value="site" <?php selected( 'site', $root['scope'] ?? 'site' ); ?>><?php echo esc_html__( 'Current site', 'wp-ai-bridge' ); ?></option><option value="network" <?php selected( 'network', $root['scope'] ?? 'site' ); ?>><?php echo esc_html__( 'Current network', 'wp-ai-bridge' ); ?></option></select></p>
			<p><?php echo esc_html__( 'Purposes', 'wp-ai-bridge' ); ?><br>
				<?php
				foreach ( array(
					'filesystem'     => __( 'File management', 'wp-ai-bridge' ),
					'logs'           => __( 'Log reading', 'wp-ai-bridge' ),
					'backup_export'  => __( 'Backup source', 'wp-ai-bridge' ),
					'backup_restore' => __( 'Restore destination', 'wp-ai-bridge' ),
				) as $purpose => $label ) :
					?>
				<label style="margin-inline-end:14px"><input type="checkbox" name="<?php echo esc_attr( $base . '[purposes][]' ); ?>" value="<?php echo esc_attr( $purpose ); ?>" <?php checked( in_array( $purpose, $root['purposes'] ?? array(), true ) ); ?>> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
			</p>
			<p><?php echo esc_html__( 'Allowed access', 'wp-ai-bridge' ); ?><br>
				<?php
				foreach ( array(
					'read'        => __( 'Read', 'wp-ai-bridge' ),
					'write'       => __( 'Create and replace', 'wp-ai-bridge' ),
					'destructive' => __( 'Delete and move', 'wp-ai-bridge' ),
					'executable'  => __( 'Code and configuration', 'wp-ai-bridge' ),
				) as $flag => $label ) :
					?>
				<label style="margin-inline-end:14px"><input type="checkbox" name="<?php echo esc_attr( $base . '[' . $flag . ']' ); ?>" value="1" <?php checked( ! empty( $root[ $flag ] ) ); ?>> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
			</p>
			<p><label><input type="checkbox" name="<?php echo esc_attr( $base . '[nonserved]' ); ?>" value="1" <?php checked( ! empty( $root['nonserved'] ) ); ?>> <?php echo esc_html__( 'The restore destination is not served by this or another website.', 'wp-ai-bridge' ); ?></label></p>
			<button type="button" class="button wpai-remove-root"><?php echo esc_html__( 'Remove this scope', 'wp-ai-bridge' ); ?></button>
		</fieldset>
		<?php
	}

	/** Native admin-post handler; validation completes before the single option update. @return void */
	public function save_configuration() {
		$configuration = new Administration_Configuration();
		if ( ! $configuration->can_configure() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Current administrator and network authority are required.', 'wp-ai-bridge' ), '', array( 'response' => 403 ) );
			return;
		}
		$config = $configuration->all();
		check_admin_referer( 'wpai-administration-configuration-' . $config['blog'] . '-' . $config['network'] );
		$posted = wp_unslash( $_POST );
		if ( ! is_string( $posted['expected_revision'] ?? null ) ||
			! hash_equals( $config['revision'], $posted['expected_revision'] ) ||
			( $posted['blog_id'] ?? null ) !== (string) $config['blog'] ||
			( $posted['network_id'] ?? null ) !== (string) $config['network'] || '1' !== ( $posted['form_complete'] ?? null ) ) {
			wp_die( esc_html__( 'Administration scopes changed or the form was incomplete. Reload and review before saving.', 'wp-ai-bridge' ), '', array( 'response' => 409 ) );
			return;
		}
		$posted['roots'] = $posted['roots'] ?? array();
		$value           = $configuration->sanitize( $posted );
		update_option( Administration_Configuration::OPTION_NAME, $value, false );
		if ( ! $configuration->can_configure() || get_current_blog_id() !== $config['blog'] ||
			get_current_network_id() !== $config['network'] || get_option( Administration_Configuration::OPTION_NAME, null ) !== $value ) {
			wp_die( esc_html__( 'The administration scopes could not be confirmed as saved. Reload and review the stored scopes.', 'wp-ai-bridge' ), '', array( 'response' => 409 ) );
			return;
		}
		wp_safe_redirect( add_query_arg( 'wpai_notice', 'administration-saved', admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}
}
