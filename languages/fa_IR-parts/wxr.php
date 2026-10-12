<?php
/**
 * Persian localization for native WXR discovery.
 *
 * @package WP_AI_Bridge
 */
return array(
	'Create Private WordPress WXR Export' => 'ایجاد خروجی خصوصی WXR وردپرس',
	'Produces a Core WXR export as an authenticated private transfer; read and verify byte windows using private-transfer, then cancel the lease.' => 'خروجی WXR وردپرس را به‌صورت انتقال خصوصی احراز هویت‌شده تولید می‌کند؛ بخش‌های فایل را با private-transfer دریافت و راستی‌آزمایی و سپس انتقال را لغو کنید.',
	'Preview Verified Private WXR Import' => 'پیش‌نمایش درون‌بری WXR خصوصی تأییدشده',
	'Read-only structural inspection of an exact verified authenticated WXR lease; no content is imported or modified.' => 'بررسی فقط‌خواندنی ساختار فایل WXR از انتقال احراز هویت‌شده و تأییدشده؛ هیچ محتوایی وارد یا ویرایش نمی‌شود.',
	'An authorized WordPress export principal and one native content type are required.' => 'کاربر دارای مجوز برون‌بری وردپرس و یک نوع محتوای معتبر لازم است.',
	'Private WXR producer cleanup requires administrator inspection.' => 'پاک‌سازی فایل موقت تولید خروجی WXR به بررسی مدیر نیاز دارد.',
	'An authorized verified WXR transfer is required to preview an import.' => 'برای پیش‌نمایش درون‌بری، انتقال WXR تأییدشده با مجوز معتبر لازم است.',
	'WXR summary exceeds the configured MCP response budget.' => 'خلاصه WXR از ظرفیت پاسخ MCP تنظیم‌شده فراتر رفته است.',
	'Discover WordPress WXR Exports'              => 'شناسایی خروجی‌های WXR وردپرس',
	'Lists WordPress-native exportable content types and exact supported filters; this read-only operation does not create or deliver a WXR file.' => 'نوع‌های محتوای قابل خروجی‌گرفتن در وردپرس و فیلترهای پشتیبانی‌شده را فهرست می‌کند؛ این عملیات فقط‌خواندنی فایل WXR ایجاد یا تحویل نمی‌دهد.',
	'Site Configuration and native WordPress export permission are required.' => 'دسترسی تنظیمات سایت و مجوز اصلی برون‌بری وردپرس لازم است.',
	'Invalid WXR export discovery selector.'      => 'پارامترهای شناسایی خروجی WXR نامعتبر است.',
	'Native exportable WordPress content types are unavailable.' => 'نوع‌های محتوای قابل برون‌بری وردپرس در دسترس نیست.',
	'Exportable content types changed; restart discovery from offset zero.' => 'نوع‌های محتوای قابل برون‌بری تغییر کرده‌اند؛ شناسایی را از ابتدا تکرار کنید.',

	'Native export and Site Configuration authorization are required.' => 'برای خروجی WXR مجوز برون‌بری وردپرس و دسترسی تنظیمات سایت لازم است.',
	'Invalid WXR export type or staging budget.'  => 'نوع محتوای خروجی WXR یا سهمیه فضای موقت نامعتبر است.',
	'This WordPress post type is not exportable.' => 'این نوع محتوای وردپرس قابلیت برون‌بری ندارد.',
	'WXR export must begin before HTTP headers have been sent.' => 'برون‌بری WXR باید پیش از ارسال سرآیندهای HTTP آغاز شود.',
	'Native WXR export could not complete within the available private staging budget.' => 'برون‌بری WXR در محدودیت فضای خصوصی تخصیص‌یافته کامل نشد.',
	'Native WXR export must use a new WordPress request for every attempt.' => 'برای هر بار برون‌بری WXR باید یک درخواست جدید وردپرس ارسال شود.',

	'Native WordPress import and Site Configuration authorization are required.' => 'برای بررسی WXR مجوز درون‌بری وردپرس و دسترسی تنظیمات سایت لازم است.',
	'Invalid private WXR preview parameters.' => 'پارامترهای بررسی WXR خصوصی نامعتبر هستند.',
	'The WXR artifact belongs to another WordPress blog.' => 'فایل WXR به سایت وردپرسی دیگری تعلق دارد.',
	'WXR preview requires an owner-only internal temporary artifact.' => 'بررسی WXR نیازمند فایل موقت داخلی با دسترسی محدود به مالک است.',
	'WXR preview exceeds the available byte budget or source length changed.' => 'حجم WXR از سهمیه موجود فراتر رفته یا اندازه فایل تغییر کرده است.',
	'WXR source SHA-256 changed after staging.' => 'اثر انگشت SHA-256 فایل WXR پس از ذخیره‌سازی موقت تغییر کرده است.',
	'Streaming XMLReader is unavailable for WXR inspection.' => 'ابزار XMLReader برای بررسی جریانی WXR در دسترس نیست.',
	'The WXR artifact did not pass bounded structural XML inspection.' => 'فایل WXR بررسی ساختاری XML با محدودیت منابع را با موفقیت نگذرانده است.',
	'The WXR document is incomplete or has an unsupported version.' => 'سند WXR ناقص است یا نسخه پشتیبانی‌نشده دارد.',
	'Invalid native WXR analysis resource budgets.' => 'سهمیه\u200cهای منابع برای تحلیل WXR با ابزار رسمی وردپرس نامعتبر است.',
	'WXR file exceeds the full-parser input byte budget; structural inspection remains available.' => 'حجم WXR از سهمیه ورودی تحلیلگر رسمی بیشتر است؛ بررسی ساختاری همچنان ممکن است.',
	'The official WordPress Importer is not enabled for this request.' => 'افزونه رسمی درون\u200cبری وردپرس برای این درخواست فعال نیست.',
	'Official WordPress Importer could not consistently parse the staged WXR.' => 'درون\u200cبر رسمی وردپرس نتوانست فایل موقت WXR را به\u200cصورت سازگار تحلیل کند.',
);
