<?php
$messages = array_merge(
	require __DIR__ . '/fa_IR-parts/part-1.php',
	require __DIR__ . '/fa_IR-parts/part-2.php',
	require __DIR__ . '/fa_IR-parts/part-3.php',
	require __DIR__ . '/fa_IR-parts/part-4.php',
	require __DIR__ . '/fa_IR-parts/part-5.php',
	require __DIR__ . '/fa_IR-parts/part-6.php',
	require __DIR__ . '/fa_IR-parts/ability-catalog.php',
	require __DIR__ . '/fa_IR-parts/media-import.php',
	require __DIR__ . '/fa_IR-parts/source-editing.php'
);

return array(
	'project-id-version' => 'WP Native Builder Bridge 0.2.0',
	'language'           => 'fa_IR',
	'messages'           => $messages,
);
