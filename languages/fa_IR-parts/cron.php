<?php
/**
 * Persian wording for bounded WP-Cron inventory.
 *
 * @package WP_AI_Bridge
 */
return array(
	'Inspect Scheduled WordPress Events'               => 'بررسی رویدادهای زمان‌بندی‌شدهٔ وردپرس',
	'Reads bounded WP-Cron event metadata with opaque identities, verified inventory pagination and no callback arguments or execution.' => 'فرادادهٔ محدود رویدادهای WP-Cron را با شناسه‌های غیرقابل‌حدس و صفحه‌بندی قابل بررسی، بدون نمایش آرگومان‌ها یا اجرای رویدادها می‌خواند.',
	'Site Configuration and WordPress administrator permissions are required.' => 'گروه دسترسی پیکربندی سایت و مجوز مدیریت وردپرس لازم است.',
	'Invalid Cron inventory selector.'                 => 'انتخاب‌گر فهرست Cron نامعتبر است.',
	'Invalid Cron event identity.'                     => 'شناسهٔ رویداد Cron نامعتبر است.',
	'Native WordPress Cron inventory was unavailable.' => 'فهرست بومی Cron وردپرس در دسترس نیست.',
	'Native Cron inventory contains unexpected data.'  => 'فهرست بومی Cron دادهٔ غیرمنتظره دارد.',
	'Native Cron inventory contains unexpected event data.' => 'فهرست بومی Cron اطلاعات رویداد غیرمنتظره دارد.',
	'Native Cron inventory contains an invalid event.' => 'فهرست بومی Cron دارای رویداد نامعتبر است.',
	'Cron inventory exceeds the bounded Bridge limit. Inspect it using native WordPress administration.' => 'فهرست Cron از ظرفیت محدود Bridge فراتر رفته است. آن را در مدیریت بومی وردپرس بررسی کنید.',
	'Native Cron event name is not valid UTF-8.'       => 'نام رویداد بومی Cron دارای UTF-8 معتبر نیست.',
	'The selected Cron event no longer exists.'        => 'رویداد Cron انتخاب‌شده دیگر وجود ندارد.',
	'The Cron inventory changed. Restart pagination from offset zero.' => 'فهرست Cron تغییر کرده است. صفحه‌بندی را از Offset صفر دوباره شروع کنید.',
	'Manage Authorized WordPress Cron Events' => 'مدیریت رویدادهای مجاز زمان‌بندی‌شده وردپرس',
	'Schedules, reschedules or cancels only provider-opted-in exact WordPress Cron events. Arguments are never supplied by a remote client. Targeted execution is not available without a safe provider runner.' => 'فقط رویدادهای معین WP-Cron را با اجازه صریح ارائه‌دهنده زمان‌بندی، تغییر یا لغو می‌کند؛ کلاینت خارجی آرگومان دلخواه نمی‌فرستد و اجرای فوری بدون اجراکننده ایمن ارائه‌دهنده مجاز نیست.',
	'Site Configuration and native administrator authority are required for Cron changes.' => 'تغییر رویدادهای Cron به دسترسی پیکربندی سایت و مجوز مدیریت بومی وردپرس نیاز دارد.',
	'The provider has not authorized this Cron operation profile.' => 'ارائه‌دهنده این عملیات یا پروفایل Cron را مجاز نکرده است.',
	'Targeted execution requires a provider-owned, idempotent runner and verified receipt. WordPress Core cannot safely run this selected event on demand.' => 'اجرای فوری دقیق به اجراکننده ایمن و تکرارپذیرِ متعلق به ارائه‌دهنده و رسید قابل‌بررسی نیاز دارد؛ هسته وردپرس امکان اجرای ایمن این رویداد معین را فراهم نمی‌کند.',
	'The selected provider recurrence is unavailable or below its supported minimum interval.' => 'تناوب موردنظر ارائه‌دهنده در دسترس نیست یا از حداقل مجاز کمتر است.',
	'An event for this exact provider-managed Cron profile already exists.' => 'برای این پروفایل مدیریت‌شده Cron رویداد دیگری وجود دارد.',
	'WordPress did not verify cancellation of the exact selected Cron event. Inspect current schedules before any retry.' => 'وردپرس لغو رویداد دقیق را تأیید نکرده است؛ پیش از تلاش دوباره رویدادهای فعلی را بررسی کنید.',
	'Rescheduling may have partially changed WordPress Cron. Inspect exact current events before any retry.' => 'ممکن است تغییر زمان Cron فقط بخشی از تغییرات را اعمال کرده باشد؛ پیش از تلاش دوباره وضعیت رویدادها را بررسی کنید.',
	'Invalid or incomplete authorized Cron mutation request.' => 'درخواست تغییر Cron ناقص یا نامعتبر است.',
	'WordPress did not verify the requested scheduled event. Inspect current schedules before retrying.' => 'وردپرس زمان‌بندی درخواست‌شده را تأیید نکرده است؛ پیش از تکرار، رویدادها را بررسی کنید.',
	'The native Cron mutation coordinator is unavailable.' => 'هماهنگ‌کننده تغییرات Cron در دسترس نیست.',
	'Another Cron change is in progress; inspect current schedules before retrying.' => 'تغییر دیگری در Cron در حال انجام است؛ پیش از تلاش دوباره وضعیت زمان‌بندی‌ها را بررسی کنید.',
	'The Cron mutation lock was lost; inspect current events before retrying.' => 'قفل تغییر Cron از دست رفته است؛ پیش از تلاش دوباره رویدادها را بررسی کنید.',
	'Resuming Cron profile discovery requires the previous profile snapshot.' => 'برای ادامه فهرست پروفایل‌های Cron باید شناسه وضعیت قبلی ارسال شود.',
	'The registered Cron profiles changed; restart at offset zero.' => 'پروفایل‌های ثبت‌شده Cron تغییر کرده‌اند؛ فهرست را از ابتدا دریافت کنید.',
	'Cron profile metadata exceeds the configured response budget.' => 'فراداده پروفایل Cron از بودجه تنظیم‌شده پاسخ بزرگ‌تر است.',
);
