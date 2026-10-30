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
		remove_submenu_page( Settings_Page::PAGE_SLUG, self::SLUG );
	}

	/** @return void */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Bridge settings.', 'wp-ai-bridge' ), '', array( 'response' => 403 ) );
			return;
		}
		?>
		<div class="wrap"><h1><?php echo esc_html__( 'Settings', 'wp-ai-bridge' ); ?></h1><p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Settings_Page::SETTINGS_SLUG . '#files-backups' ) ); ?>"><?php echo esc_html__( 'Administration controls are now in Settings.', 'wp-ai-bridge' ); ?></a></p></div>
		<?php
	}

	/** Redirect old bookmarks before admin output; existing POST handlers stay independent. @return void */
	public function redirect_legacy() {
		if ( 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_GET['page'] ) && self::SLUG === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only legacy route.
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			wp_safe_redirect( admin_url( 'admin.php?page=' . Settings_Page::SETTINGS_SLUG . '#files-backups' ) );
			exit;
		}
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
