<?php
/** Persian translations for bounded MCP content and Gutenberg inspection. */
return array(
	'A resumed content read requires expected_content_hash from the previous response.' => 'برای ادامهٔ خواندن محتوا، باید expected_content_hash پاسخ قبلی فرستاده شود.',
	'The content changed while reading its windows; restart from offset zero.' => 'محتوا هنگام خواندن بخش‌های آن تغییر کرده است؛ خواندن را از offset صفر شروع کنید.',
	'The requested content result exceeds the MCP response budget. Reduce per_page, disable include_content, or use content-read with content_offset and content_max_bytes.' => 'حجم نتیجهٔ محتوا از سقف پاسخ MCP بیشتر است. per_page را کاهش دهید، include_content را غیرفعال کنید یا از content-read با content_offset و content_max_bytes استفاده کنید.',
	'Find Gutenberg Blocks' => 'پیدا کردن بلوک‌های گوتنبرگ',
	'Scans bounded batches of blocks and returns stable paths and fingerprints without expanding the block tree.' => 'دسته‌های محدود بلوک‌ها را بررسی می‌کند و بدون باز کردن تمام درخت بلوک، مسیرها و اثرانگشت‌های پایدار را برمی‌گرداند.',
	'The content changed after inspection; restart the bounded read.' => 'محتوا پس از بررسی تغییر کرده است؛ خواندن محدود را دوباره آغاز کنید.',
	'A continuation scan requires expected_content_hash from the previous response.' => 'برای ادامهٔ جستجو، expected_content_hash پاسخ قبلی لازم است.',
	'The content changed after inspection; restart the block search.' => 'محتوا پس از بررسی تغییر کرده است؛ جستجوی بلوک‌ها را دوباره آغاز کنید.',
	'The Gutenberg result is too large for a normal MCP response. Use blocks-find with continuation, then blocks-read with path and max_depth, or disable attrs for a large target.' => 'حجم نتیجهٔ گوتنبرگ برای پاسخ عادی MCP زیاد است. از blocks-find با ادامهٔ جستجو و سپس blocks-read با path و max_depth استفاده کنید؛ برای بلوک‌های حجیم نیز attrs را غیرفعال کنید.',
	'The requested content window is outside the supported bounds.' => 'بخش درخواستی محتوا خارج از محدودهٔ مجاز است.',
	'The content window must start at a UTF-8 character boundary.' => 'بخش محتوا باید از مرز معتبر یک نویسهٔ UTF-8 شروع شود.',
	'The selected content cannot be represented as a UTF-8 window.' => 'محتوای انتخاب‌شده را نمی‌توان به‌صورت بخش معتبر UTF-8 ارائه کرد.',
);
