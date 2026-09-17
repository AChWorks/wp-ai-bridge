<?php
error_reporting( E_ALL );

define( 'ABSPATH', '/tmp/wp/' );
define( 'WP_AI_BRIDGE_VERSION', '0.4.0' );

$GLOBALS['wp_version'] = '7.1';
$GLOBALS['wpai_test']  = array(
	'options'               => array(),
	'capabilities'          => array( 'read' => true, 'manage_options' => true ),
	'user_id'               => 1,
	'registered_settings'   => array(),
	'registered_categories' => array(),
	'registered_abilities'  => array(),
	'abilities'             => array(),
	'actions'               => array(),
	'options_pages'         => array(),
	'menu_pages'            => array(),
	'submenu_pages'         => array(),
	'settings_fields'       => array(),
	'plugins'               => array(),
	'post_types'            => array(),
	'post_type_supports'    => array(),
	'taxonomies'            => array(),
	'theme'                 => array(
		'Name'       => 'Test Theme',
		'Version'    => '1.0.0',
		'stylesheet' => 'test-theme',
		'template'   => 'test-theme',
		'block'      => false,
	),
);
$GLOBALS['submenu'] = array();

function wpai_test_reset_state() {
	$GLOBALS['wpai_test']['options']               = array();
	$GLOBALS['wpai_test']['capabilities']          = array( 'read' => true, 'manage_options' => true );
	$GLOBALS['wpai_test']['registered_settings']   = array();
	$GLOBALS['wpai_test']['registered_categories'] = array();
	$GLOBALS['wpai_test']['registered_abilities']  = array();
	$GLOBALS['wpai_test']['abilities']             = array();
	$GLOBALS['wpai_test']['actions']               = array();
	$GLOBALS['wpai_test']['options_pages']         = array();
	$GLOBALS['wpai_test']['menu_pages']            = array();
	$GLOBALS['wpai_test']['submenu_pages']         = array();
	$GLOBALS['submenu']                            = array();
	$GLOBALS['wpai_test']['settings_fields']       = array();
	$GLOBALS['wpai_test']['plugins']               = array();
	$GLOBALS['wpai_test']['post_types']            = array();
	$GLOBALS['wpai_test']['post_type_supports']    = array();
	$GLOBALS['wpai_test']['taxonomies']            = array();
	$GLOBALS['wpai_test']['theme']                 = array(
		'Name'       => 'Test Theme',
		'Version'    => '1.0.0',
		'stylesheet' => 'test-theme',
		'template'   => 'test-theme',
		'block'      => false,
	);
}

/**
 * Minimal WordPress error object for dependency-free provider tests.
 */
class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
	}

	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function has_errors() { return '' !== $this->code; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

/**
 * Minimal Ability object for dependency-free tests.
 */
final class WP_Native_Builder_Test_Ability {
	private $name;
	private $label;
	private $description;
	private $category;
	private $input_schema;
	private $meta;

	public function __construct( $name, $label = '', $description = '', $category = 'test', $input_schema = array(), $meta = array() ) {
		$this->name         = (string) $name;
		$this->label        = (string) $label;
		$this->description  = (string) $description;
		$this->category     = (string) $category;
		$this->input_schema = is_array( $input_schema ) ? $input_schema : array();
		$this->meta         = is_array( $meta ) ? $meta : array();
	}

	public function get_name() { return $this->name; }
	public function get_label() { return $this->label; }
	public function get_description() { return $this->description; }
	public function get_category() { return $this->category; }
	public function get_input_schema() { return $this->input_schema; }
	public function get_meta() { return $this->meta; }
}

/**
 * Minimal theme object for dependency-free tests.
 */
final class WP_Native_Builder_Test_Theme {
	private $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function get( $field ) {
		return isset( $this->data[ $field ] ) ? $this->data[ $field ] : '';
	}

	public function get_stylesheet() {
		return isset( $this->data['stylesheet'] ) ? $this->data['stylesheet'] : '';
	}

	public function get_template() {
		return isset( $this->data['template'] ) ? $this->data['template'] : '';
	}
}

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return (string) $url; }
function get_bloginfo( $show = '' ) { return '7.1'; }
function get_locale() { return 'en_US'; }
function is_rtl() { return false; }
function wp_timezone_string() { return 'UTC'; }
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( $path, '/' ); }
function home_url( $path = '' ) { return 'https://example.test' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) ); }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function current_user_can( $capability ) { return ! empty( $GLOBALS['wpai_test']['capabilities'][ $capability ] ); }
function get_current_user_id() { return (int) $GLOBALS['wpai_test']['user_id']; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['wpai_test']['options'] ) ? $GLOBALS['wpai_test']['options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['wpai_test']['options'][ $name ] = $value; return true; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_kses_post( $value ) { return strip_tags( (string) $value, '<p><a><strong><em><code><pre><ul><ol><li><blockquote>' ); }
function wp_check_invalid_utf8( $value, $strip = false ) { return (string) $value; }
function wp_json_encode( $value, $flags = 0, $depth = 512 ) { return json_encode( $value, $flags, $depth ); }
function wp_slash( $value ) { return $value; }
function wp_unslash( $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['wpai_test']['actions'][ $hook ][] = $callback; return true; }
function register_setting( $group, $name, $args = array() ) { $GLOBALS['wpai_test']['registered_settings'][ $name ] = array( 'group' => $group, 'args' => $args ); }
function add_options_page( $page_title, $menu_title, $capability, $slug, $callback ) { $GLOBALS['wpai_test']['options_pages'][ $slug ] = compact( 'page_title', 'menu_title', 'capability', 'slug', 'callback' ); return $slug; }
function add_menu_page( $page_title, $menu_title, $capability, $slug, $callback = '', $icon_url = '', $position = null ) {
	$GLOBALS['wpai_test']['menu_pages'][ $slug ] = compact( 'page_title', 'menu_title', 'capability', 'slug', 'callback', 'icon_url', 'position' );
	$GLOBALS['submenu'][ $slug ] = array( array( $menu_title, $capability, $slug, $page_title ) );
	return $slug;
}
function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '' ) {
	$GLOBALS['wpai_test']['submenu_pages'][ $parent_slug ][ $menu_slug ] = compact( 'parent_slug', 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback' );
	$GLOBALS['submenu'][ $parent_slug ][] = array( $menu_title, $capability, $menu_slug, $page_title );
	return $menu_slug;
}
function settings_fields( $group ) { $GLOBALS['wpai_test']['settings_fields'][] = $group; echo '<input type="hidden" name="_wpnonce" value="test">'; }
function checked( $checked, $current = true, $echo = true ) { $result = $checked == $current ? 'checked="checked"' : ''; if ( $echo ) { echo $result; } return $result; }
function selected( $selected, $current = true, $echo = true ) { $result = $selected == $current ? 'selected="selected"' : ''; if ( $echo ) { echo $result; } return $result; }
function submit_button( $text = 'Save Changes', $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button type="submit">' . esc_html( $text ) . '</button>'; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) { $html = '<input type="hidden" name="' . esc_attr( $name ) . '" value="test">'; if ( $display ) { echo $html; } return $html; }
function wp_die( $message ) { throw new RuntimeException( (string) $message ); }
function wp_register_ability_category( $slug, $args ) { $GLOBALS['wpai_test']['registered_categories'][ $slug ] = $args; return (object) array( 'slug' => $slug ); }
function wp_register_ability( $name, $args ) { $GLOBALS['wpai_test']['registered_abilities'][ $name ] = $args; return (object) array( 'name' => $name ); }
function wp_get_ability( $name ) { return isset( $GLOBALS['wpai_test']['abilities'][ $name ] ) ? $GLOBALS['wpai_test']['abilities'][ $name ] : null; }
function wp_get_abilities( $args = array() ) { return array_values( $GLOBALS['wpai_test']['abilities'] ); }
function wp_get_theme() { return new WP_Native_Builder_Test_Theme( $GLOBALS['wpai_test']['theme'] ); }
function wp_is_block_theme() { return ! empty( $GLOBALS['wpai_test']['theme']['block'] ); }
function get_plugins() { return $GLOBALS['wpai_test']['plugins']; }
function get_post_types( $args = array(), $output = 'names' ) { return 'objects' === $output ? $GLOBALS['wpai_test']['post_types'] : array_keys( $GLOBALS['wpai_test']['post_types'] ); }
function get_post_type_object( $post_type ) { return isset( $GLOBALS['wpai_test']['post_types'][ $post_type ] ) ? $GLOBALS['wpai_test']['post_types'][ $post_type ] : null; }
function post_type_supports( $post_type, $feature ) { return ! empty( $GLOBALS['wpai_test']['post_type_supports'][ $post_type ][ $feature ] ); }
function get_taxonomies( $args = array(), $output = 'names' ) { return 'objects' === $output ? $GLOBALS['wpai_test']['taxonomies'] : array_keys( $GLOBALS['wpai_test']['taxonomies'] ); }

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'WP_AI_Bridge\\';
		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$relative   = substr( $class, strlen( $prefix ) );
		$parts      = explode( '\\', $relative );
		$class_file = 'class-' . strtolower( str_replace( '_', '-', array_pop( $parts ) ) . '.php' );
		$directory  = $parts ? implode( '/', $parts ) . '/' : '';
		$path       = dirname( __DIR__ ) . '/src/' . $directory . $class_file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
