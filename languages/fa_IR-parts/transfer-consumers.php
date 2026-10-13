<?php
/**
 * Private Media egress and explicit native content consumer.
 *
 * @package WP_AI_Bridge
 */

return array(
	'Export Media as Private Transfer' => 'خروجی رسانه به‌صورت انتقال خصوصی',
	'Creates an authenticated private byte snapshot of one readable WordPress Media attachment; retrieve bounded windows using private-transfer read.' => 'از فایل رسانهٔ قابل‌خواندن در وردپرس یک نسخهٔ خصوصی و احرازشده ایجاد می‌کند؛ قطعات محدود آن را با عملیات خواندن انتقال خصوصی دریافت کنید.',
	'A readable WordPress Media attachment and a currently authorized MCP client are required.' => 'فایل رسانهٔ قابل‌خواندن و کلاینت MCP با مجوز معتبر لازم هستند.',
	'The Media source is unavailable inside the current WordPress uploads directory.' => 'منبع رسانه در پوشهٔ بارگذاری‌های معتبر وردپرس در دسترس نیست.',
	'Requested outbound window exceeds the current MCP response budget. Request fewer bytes or adjust the administrator response setting.' => 'اندازهٔ قطعهٔ خروجی از بودجهٔ پاسخ MCP بیشتر است. قطعهٔ کوچک‌تری درخواست کنید یا تنظیم مدیر را تغییر دهید.',
	'Commit Verified Text to WordPress Content' => 'ثبت متن تأییدشده در محتوای وردپرس',
	'Creates a draft or updates one editable WordPress post using a verified private text transfer. Updates require an exact current content identity. A claimed effect is never blindly retried.' => 'با انتقال خصوصیِ متنِ تأییدشده، پیش‌نویس می‌سازد یا یک نوشتهٔ قابل‌ویرایش را به‌روزرسانی می‌کند. ویرایش به شناسهٔ دقیق وضعیت فعلی نیاز دارد و عملیات ادعاشده بدون بررسی تکرار نمی‌شود.',
	'Current OAuth client, Builder Write and WordPress content editing authority are required.' => 'کلاینت OAuth معتبر، مجوز نوشتن سازنده و اختیار ویرایش محتوای وردپرس ضروری است.',
	'The current PHP memory limit cannot safely materialize this WordPress content update. Increase host memory or use a suitable native provider.' => 'حافظهٔ PHP میزبان برای پردازش ایمن این محتوا کافی نیست. حافظهٔ میزبان را افزایش دهید یا از ارائه‌دهندهٔ بومی مناسب استفاده کنید.',
	'The verified text transfer changed while preparing its WordPress consumer.' => 'محتوای انتقال متنِ تأییدشده هنگام آماده‌سازی برای وردپرس تغییر کرده است.',
	'WordPress content requires complete UTF-8 text without NUL bytes; the private transfer remains unchanged.' => 'متن وردپرس باید UTF-8 کامل و بدون بایت NUL باشد؛ انتقال خصوصی تغییری نکرده است.',
	'WordPress content authority changed before the transfer could be committed.' => 'مجوز ویرایش وردپرس پیش از ثبت انتقال تغییر کرد.',
	'A valid text lease and exact, authorized WordPress content target are required.' => 'انتقال متن معتبر و مقصد دقیق دارای مجوز در وردپرس ضروری است.',
	'The WordPress text effect may have occurred. Inspect the exact post and transfer receipt; do not automatically retry.' => 'ممکن است متن در وردپرس ثبت شده باشد. نوشته و رسید انتقال را بررسی کنید و بدون بررسی دوباره اجرا نکنید.',
);
