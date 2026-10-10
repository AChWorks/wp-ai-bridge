<?php
/** Persian messages for independently delegated registered REST invocation. */
return array(
	'High-Trust Registered REST Invocation' => 'اجرای پر‌اعتماد REST ثبت‌شده',
	'Allow the current WordPress administrator to invoke bounded native registered REST routes, including mutations, under native provider permission. Sensitive Bridge and credential control paths remain excluded. This is high trust, not a sandbox.' => 'به مدیر فعلی وردپرس اجازه می‌دهد مسیرهای ثبت‌شده REST، از جمله عملیات تغییردهنده، را با محدودیت داده و کنترل مجوز اصلی ارائه‌دهنده اجرا کند. مسیرهای حساس مدیریت Bridge و اعتبارنامه‌ها مستثنا هستند. این اختیار پر‌اعتماد است و محیط ایزوله نیست.',
	'Invoke Registered REST Route' => 'اجرای مسیر REST ثبت‌شده',
	'Invokes one bounded, public-index-visible local WordPress REST route using native permissions. Requires separate high-trust grant. Can mutate data and is not idempotent.' => 'یک مسیر محلی و قابل مشاهده در نمایه عمومی REST وردپرس را با مجوز اصلی اجرا می‌کند. به اجازه مستقل پر‌اعتماد نیاز دارد، می‌تواند داده‌ها را تغییر دهد و تکرار آن لزوماً ایمن نیست.',
	'Registered REST invocation requires a separate enabled Bridge grant and WordPress administration permission.' => 'اجرای REST ثبت‌شده به اجازه مستقل و فعال Bridge و مجوز مدیریتی وردپرس نیاز دارد.',
	'Nested generic REST invocation is not allowed.' => 'اجرای تودرتوی REST عمومی مجاز نیست.',
	'The native WordPress REST dispatcher is unavailable.' => 'مسیر اجرای اصلی REST وردپرس در دسترس نیست.',
	'The local REST route registry cannot be safely inspected.' => 'فهرست مسیرهای REST محلی را نمی‌توان به شکل ایمن بررسی کرد.',
	'Multiple registered REST routes match the requested path.' => 'چند مسیر REST ثبت‌شده با مسیر درخواستی تطبیق دارند.',
	'WordPress did not report a successful REST operation. Review current state before retrying a mutation.' => 'وردپرس موفقیت عملیات REST را گزارش نکرد. پیش از تکرار عملیات تغییردهنده، وضعیت فعلی را بررسی کنید.',
	'The REST operation outcome cannot be confirmed. Inspect provider state before retrying.' => 'نتیجه عملیات REST قابل تأیید نیست. پیش از تکرار، وضعیت ارائه‌دهنده را بررسی کنید.',
	'The REST response cannot be returned safely within the data limit.' => 'بازگرداندن پاسخ REST در محدودیت ایمن داده ممکن نیست.',
	'Specify one exact registered route, a matching local path, an allowed method and bounded JSON parameters.' => 'یک مسیر ثبت‌شده دقیق، مسیر محلی متناظر، متد مجاز و پارامترهای JSON محدود ارائه کنید.',
	'The requested public registered REST route and method are not available.' => 'مسیر REST ثبت‌شده عمومی و متد درخواستی در دسترس نیست.',
);
