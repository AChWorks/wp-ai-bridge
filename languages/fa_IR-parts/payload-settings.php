<?php
/**
 * Persian messages for operator-configurable per-request budgets.
 *
 * @package WP_AI_Bridge
 */
return array(
	'Workspace record storage limits below are separate quotas; they do not change the maximum MCP request size or create a file upload path.' => 'سقف ذخیرهٔ رکوردهای Workspace در پایین مستقل است؛ اندازهٔ درخواست MCP را تغییر نمی‌دهد و مسیر آپلود فایل ایجاد نمی‌کند.',
	'Workspace document storage limit (KiB per document)' => 'سقف ذخیرهٔ سند Workspace (KiB به‌ازای هر سند)',
	'Default: 512 KiB; configurable from 64 to 1024 KiB. This bounds one private Workspace document, not WordPress posts or file transport.' => 'پیش‌فرض: ۵۱۲ KiB؛ قابل تنظیم از ۶۴ تا ۱۰۲۴ KiB. این سقف برای یک سند خصوصی Workspace است، نه نوشته‌های وردپرس یا انتقال فایل.',
	'Workspace task notes storage limit (KiB per task)' => 'سقف ذخیرهٔ یادداشت وظیفهٔ Workspace (KiB به‌ازای هر وظیفه)',
	'Default: 128 KiB; configurable from 16 to 256 KiB. Lowering a limit does not erase existing longer notes, but blocks oversized replacements.' => 'پیش‌فرض: ۱۲۸ KiB؛ قابل تنظیم از ۱۶ تا ۲۵۶ KiB. کاهش سقف، یادداشت‌های قبلی را پاک نمی‌کند، اما جایگزینی حجیم‌تر از سقف را نمی‌پذیرد.',
	'The transfer budget must be a whole number within the indicated range and units. The previous value was kept.' => 'بودجهٔ انتقال باید عدد صحیح در بازه و واحد مشخص‌شده باشد. مقدار قبلی حفظ شد.',
	'Units: 1 KiB = 1,024 bytes; 1 MiB = 1,024 KiB; 128 KiB is about 131 KB.' => 'واحدها: ۱ KiB = ۱٬۰۲۴ بایت؛ ۱ MiB = ۱٬۰۲۴ KiB؛ ۱۲۸ KiB تقریباً ۱۳۱ KB است.',
	'MCP response and text budgets' => 'بودجهٔ پاسخ MCP و بخش‌های متن',
	'These are per-request response and text-window budgets, not limits on the size of a WordPress document or transferred file. No capacity is preallocated when idle.' => 'این مقادیر بودجهٔ هر پاسخ و بخش متن هستند، نه محدودیت اندازهٔ سند وردپرس یا فایل انتقالی. در حالت بیکار ظرفیتی از پیش رزرو نمی‌شود.',
	'MCP JSON response budget (KiB)' => 'بودجهٔ پاسخ JSON در MCP (KiB)',
	'Encoded result size, including Unicode JSON escaping. Default: 128 KiB; configurable from 16 to 1024 KiB. Older Gateways with a 64 KiB limit require a smaller value (such as 48 KiB) until upgraded.' => 'حجم نتیجهٔ کدگذاری‌شده شامل تبدیل یونی‌کد در JSON است. پیش‌فرض: ۱۲۸ KiB؛ بازهٔ تنظیم: ۱۶ تا ۱۰۲۴ KiB. Gateway قدیمی با سقف ۶۴ KiB، تا پیش از ارتقا به مقدار کوچک‌تری مانند ۴۸ KiB نیاز دارد.',
	'Maximum UTF-8 text window (KiB)' => 'بیشینهٔ اندازهٔ بخش متن UTF-8 (KiB)',
	'Maximum source bytes per requested text window. Default: 128 KiB; configurable from 4 to 512 KiB. A response may return a shorter window to fit JSON encoding. The entire text remains readable using offsets and hashes.' => 'حداکثر بایت متن اصلی در هر بخش درخواستی. پیش‌فرض: ۱۲۸ KiB؛ بازهٔ تنظیم: ۴ تا ۵۱۲ KiB. برای جاگیری در JSON ممکن است بخش کوچک‌تری برگردد. کل متن با Offset و Hash همچنان قابل خواندن است.',
	'Bridge cannot change MCP client, Gateway, proxy, PHP or hosting limits. Increase those separately where supported; large private files still require an authenticated binary transport.' => 'بریج نمی‌تواند محدودیت‌های کلاینت MCP، Gateway، پراکسی، PHP یا میزبانی را تغییر دهد. در صورت پشتیبانی آن‌ها را جدا تنظیم کنید؛ فایل‌های خصوصی بزرگ همچنان به مسیر انتقال باینری احراز هویت‌شده نیاز دارند.',
	'Save response and text budgets' => 'ذخیرهٔ بودجهٔ پاسخ و متن',
);
