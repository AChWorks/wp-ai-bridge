<?php
/**
 * Real WordPress runtime smoke for the bundled Persian translation catalog.
 *
 * @package WP_AI_Bridge
 */

function wpai_issue6_i18n_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$domain        = 'wp-ai-bridge';
$language_file = dirname( WP_AI_BRIDGE_FILE ) . '/languages/wp-ai-bridge-fa_IR.l10n.php';

wpai_issue6_i18n_assert( file_exists( $language_file ), 'Bundled fa_IR runtime catalog is missing.' );

unload_textdomain( $domain );
$loaded = load_textdomain( $domain, $language_file, 'fa_IR' );
wpai_issue6_i18n_assert( true === $loaded, 'WordPress could not load the bundled fa_IR runtime catalog.' );

wpai_issue6_i18n_assert(
	'اتصال مستقیم به ChatGPT App' === __( 'Direct ChatGPT App', $domain ),
	'Persian admin connection heading did not load at runtime.'
);
wpai_issue6_i18n_assert(
	'گروه‌های دسترسی' === __( 'Access groups', $domain ),
	'Persian access-group heading did not load at runtime.'
);
wpai_issue6_i18n_assert(
	'محتوای زنده' === __( 'Live Content', $domain ),
	'Persian access-group label did not load at runtime.'
);
wpai_issue6_i18n_assert(
	'تأیید دسترسی ChatGPT' === __( 'Authorize ChatGPT', $domain ),
	'Persian OAuth consent action did not load at runtime.'
);
wpai_issue6_i18n_assert(
	'خطای احراز مجوز OAuth' === __( 'OAuth authorization error', $domain ),
	'Persian OAuth error heading did not load at runtime.'
);
wpai_issue6_i18n_assert(
	'پیشخوان' === __( 'Dashboard', $domain ),
	'Persian Workspace dashboard label did not load at runtime.'
);
wpai_issue6_i18n_assert(
	'چرخهٔ عمر داده‌های فضای کاری' === __( 'Workspace data lifecycle', $domain ),
	'Persian Workspace lifecycle label did not load at runtime.'
);
wpai_issue6_i18n_assert(
	'در حال انجام' === __( 'In progress', $domain ),
	'Persian Workspace task-state label did not load at runtime.'
);

unload_textdomain( $domain );

echo "PASS: Issue #6 bundled fa_IR runtime translations.\n";
