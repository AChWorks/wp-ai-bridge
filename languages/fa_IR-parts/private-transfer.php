<?php
/**
 * Persian localization for site-configurable private transfer policy.
 *
 * @package WP_AI_Bridge
 */
return array(
	'Incoming MCP request budget (KiB)' => 'بودجه درخواست ورودی MCP (کیبی‌بایت)',
	'Bounds one encoded inbound tool message, not the aggregate file. Larger WordPress, proxy or MCP client limits must be configured separately.' => 'برای یک پیام ورودی رمزگذاری‌شده ابزار است، نه کل فایل. سقف‌های وردپرس، پراکسی یا کلاینت MCP را در صورت نیاز جداگانه تنظیم کنید.',
	'Maximum raw transfer chunk (KiB)' => 'حداکثر اندازه بخش خام انتقال (کیبی‌بایت)',
	'Chunk bytes are also reduced automatically to fit the effective incoming MCP JSON budget. This is not a total file-size ceiling.' => 'اندازه بخش به صورت خودکار برای جاگیری در بودجه مؤثر JSON ورودی MCP کاهش می‌یابد. این محدودیت حجم کل فایل نیست.',
	'One staged transfer reservation (MiB)' => 'رزرو یک انتقال موقت (مبی‌بایت)',
	'Adjust for actual private disk capacity and workloads. No archive, document or file extension has a universal Bridge-side aggregate cap.' => 'با توجه به ظرفیت واقعی فضای خصوصی و بار کاری تنظیم کنید. هیچ فرمت آرشیو، سند یا پسوند فایل سقف یکسان سراسری در بریج ندارد.',
	'Aggregate live reservation budget (MiB)' => 'بودجه مجموع رزروهای فعال (مبی‌بایت)',
	'Limits total outstanding private storage reservations per WordPress site independently of the per-file budget.' => 'مجموع رزروهای باقی‌مانده ذخیره‌سازی خصوصی را برای هر سایت وردپرس مستقل از بودجه هر فایل محدود می‌کند.',
	'Concurrent private transfer sessions' => 'نشست‌های هم‌زمان انتقال خصوصی',
	'Maximum non-expired transfers; expired sessions are cleaned on subsequent admissions.' => 'حداکثر انتقال‌هایی که منقضی نشده‌اند؛ نشست‌های منقضی در پذیرش‌های بعدی پاک می‌شوند.',
	'Private transfer lease lifetime (minutes)' => 'مدت اعتبار نشست انتقال خصوصی (دقیقه)',
	'Retention is finite and separate from one HTTP request timeout. The host must preserve temporary storage across requests.' => 'مدت نگهداری محدود و مستقل از زمان انتظار یک درخواست HTTP است. هاست باید فضای موقت را میان درخواست‌ها حفظ کند.',
	'Private file transfer capacity' => 'ظرفیت انتقال فایل خصوصی',
	'Adjust these values without changing plugin source. They reserve finite, revocable private transfer resources; WordPress, PHP, filesystem, client and Gateway constraints still apply.' => 'این مقادیر را بدون تغییر کد افزونه تنظیم کنید. منابع محدود و قابل لغو رزرو می‌شوند؛ محدودیت‌های وردپرس، PHP، فایل‌سیستم، کلاینت و Gateway همچنان برقرارند.',
	'Current PHP-derived maximum:' => 'حداکثر محاسبه‌شده بر اساس PHP کنونی:',
	'Save private transfer capacity' => 'ذخیره ظرفیت انتقال خصوصی',
	'Transfer Private File Bytes' => 'انتقال بایت‌های فایل خصوصی',
	'Authenticated, resumable byte staging using begin/append/status/verify/cancel. Never installs, imports, executes or publishes staged data.' => 'ذخیره موقت بایت‌های احراز هویت‌شده و قابل ادامه با begin/append/status/verify/cancel؛ داده‌های موقت هرگز نصب، وارد، اجرا یا منتشر نمی‌شوند.',
	'An authenticated MCP client and current delegated WordPress authority are required.' => 'کلاینت MCP احراز هویت‌شده و مجوز واگذارشده فعلی وردپرس لازم است.',
	'Invalid transfer operation.' => 'عملیات انتقال نامعتبر است.',
	'The file exceeds the current administrator-configured transfer size. Increase the transfer file and reservation budgets in WP AI Bridge Settings.' => 'حجم فایل از ظرفیت تنظیم‌شده مدیر بیشتر است. بودجه فایل و رزرو را در تنظیمات WP AI Bridge افزایش دهید.',
	'The transfer chunk exceeds the configured incoming MCP message budget. Use a smaller chunk or adjust the WordPress administrator budget.' => 'اندازه بخش انتقال از بودجه پیام ورودی MCP بیشتر است. بخش کوچک‌تر بفرستید یا بودجه را در تنظیمات مدیر وردپرس تغییر دهید.',
	'The encoded incoming transfer message exceeds the configured MCP request budget.' => 'پیام ورودی کدگذاری‌شده انتقال از بودجه تنظیم‌شده درخواست MCP بیشتر است.',
	'The private transfer request or expected session identity is invalid.' => 'درخواست انتقال خصوصی یا شناسه مورد انتظار نشست نامعتبر است.',
	'Transfer capacity must be a positive whole number within the current PHP resource limits. The previous setting was kept.' => 'ظرفیت انتقال باید عدد صحیح مثبت در محدوده منابع PHP فعلی باشد. مقدار قبلی حفظ شد.',
	'Import Verified Transfer into Media' => 'واردکردن انتقال تأییدشده به رسانه‌های وردپرس',
	'Consumes one previously verified private transfer as a new WordPress Media Library file. Side effects are not automatically retried. Does not install, unpack or run archives.' => 'یک انتقال خصوصی تأییدشده را به عنوان فایل جدید در کتابخانه رسانه وردپرس وارد می‌کند. عملیات دارای اثر به طور خودکار تکرار نمی‌شود و آرشیوها را نصب، استخراج یا اجرا نمی‌کند.',
	'Authenticated client, Builder Write and WordPress upload authority are required for Media import.' => 'برای واردکردن رسانه، کلاینت احراز هویت‌شده، مجوز Builder Write و اجازه بارگذاری وردپرس لازم است.',
	'WordPress does not allow this file type for the current upload principal.' => 'وردپرس این نوع فایل را برای کاربر فعلی بارگذاری مجاز نمی‌داند.',
	'Media consumption requires an unclaimed, fully verified private transfer. Inspect its status before another action.' => 'ورود به رسانه نیازمند انتقال خصوصی کامل و تأییدشده‌ای است که قبلاً مصرف نشده باشد. پیش از اقدام جدید وضعیت آن را بررسی کنید.',
	'WordPress Media Library does not support an empty sideload; keep the verified zero-byte transfer private.' => 'کتابخانه رسانه وردپرس از واردکردن فایل خالی پشتیبانی نمی‌کند؛ انتقال صفر‌بایتی تأییدشده را خصوصی نگه دارید.',
	'The transfer is larger than the current native WordPress upload limit. Increase the WordPress/host setting or use another authorized consumer.' => 'اندازه انتقال از محدودیت بومی بارگذاری وردپرس بیشتر است. تنظیمات وردپرس یا هاست را افزایش دهید یا مصرف‌کننده مجاز دیگری به کار برید.',
	'The requested Media consumer identity or filename is invalid.' => 'شناسه انتقال یا نام فایل درخواستی برای رسانه نامعتبر است.',
	'The Media operation may be incomplete. Inspect the exact WordPress Media destination and transfer status; do not retry blindly.' => 'عملیات رسانه ممکن است ناقص باشد. مقصد دقیق رسانه وردپرس و وضعیت انتقال را بررسی کنید و بدون اطمینان دوباره تلاش نکنید.',
);
