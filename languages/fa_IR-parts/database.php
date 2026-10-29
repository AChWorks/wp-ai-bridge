<?php
/** Database administration translations. @package WP_AI_Bridge */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
return array(
	'Only a Super Admin with current network administration authority can change database delegation on multisite. Existing values are preserved when you save other settings.' => 'در وردپرس چندسایتی، تنها مدیر ارشد دارای اختیار فعلی مدیریت شبکه می‌تواند مجوزهای دیتابیس را تغییر دهد. هنگام ذخیرهٔ تنظیمات دیگر، مقادیر فعلی این مجوزها حفظ می‌شوند.',
	'The selected live row position is no longer available. Refresh the filtered row page before continuing.' => 'موقعیت زندهٔ انتخاب‌شدهٔ سطر دیگر در دسترس نیست. پیش از ادامه، صفحهٔ سطرهای فیلترشده را تازه‌سازی کنید.',
	'The database did not provide a valid SHA-256 for this value. Verify database SHA2 support and refresh the read; unverified bytes cannot be returned.' => 'دیتابیس هش SHA-256 معتبر برای این مقدار ارائه نکرد. پشتیبانی SHA2 دیتابیس را بررسی و خواندن را تازه‌سازی کنید؛ بایت‌های تأییدنشده قابل ارائه نیستند.',
	'Read Sensitive Database Values' => 'خواندن مقادیر حساس دیتابیس',
	'Inspect Installation Database' => 'بازرسی دیتابیس نصب وردپرس',
	'Read exact installation/plugin table bytes, including potential secrets, with bounded pages and hash-checked cell windows. Requires explicit Sensitive Database Read and Database Inspection; multisite requires Super Admin.' => 'خواندن دقیق بایت‌های جدول‌های وردپرس و افزونه‌ها، شامل داده‌های احتمالاً محرمانه، با صفحه‌بندی و بررسی هش بخش‌های مقدار. مجوزهای صریح خواندن حساس دیتابیس و بازرسی دیتابیس لازم‌اند؛ در چندسایتی مدیر ارشد شبکه لازم است.',
	'Discover installation-prefixed base tables, columns and indexes through the current WordPress connection. No record/default values; multisite requires Super Admin.' => 'کشف جدول‌های اصلی با پیشوند نصب، ستون‌ها و ایندکس‌ها از اتصال فعلی وردپرس. مقادیر رکوردها و پیش‌فرض‌ها نمایش داده نمی‌شوند؛ در چندسایتی مدیر ارشد شبکه لازم است.',
	'Enable the corresponding database grants in WP AI Bridge Settings and use a current WordPress administrator. Multisite also requires actual Super Admin and network authority.' => 'مجوزهای مربوط به دیتابیس را در تنظیمات WP AI Bridge فعال کنید و از حساب مدیر فعلی وردپرس استفاده کنید. در چندسایتی، مدیر ارشد واقعی و اختیار مدیریت شبکه نیز لازم است.',
	'This page exceeds the configured response budget. Request fewer records/columns or a shorter cell window, or adjust the response policy in WP AI Bridge Settings.' => 'این صفحه از بودجهٔ پاسخ تنظیم‌شده بزرگ‌تر است. رکورد یا ستون کمتر، یا بخش کوتاه‌تری از مقدار بخواهید؛ یا سیاست پاسخ را در تنظیمات WP AI Bridge تغییر دهید.',
	'The database read audit could not be saved. Restore WordPress option storage before retrying this read.' => 'ثبت رویداد خواندن دیتابیس ممکن نشد. پیش از تلاش دوباره، ذخیره‌سازی تنظیمات وردپرس را اصلاح کنید.',
	'Use the action-specific database schema: exact table/columns, canonical base64 filter/key values, finite page/window sizes within the response policy, and the previous SHA-256 for cell continuation.' => 'از ساختار ورودی مخصوص عملیات استفاده کنید: نام دقیق جدول و ستون، مقادیر معیار و کلید با base64 استاندارد، اندازهٔ صفحه و بخش مقدار در محدودهٔ پاسخ، و SHA-256 قبلی برای ادامهٔ خواندن مقدار.',
	'Database Inspection' => 'بازرسی دیتابیس',
	'Inspect installation table, column and index metadata using the WordPress database connection. Multisite requires Super Admin. This does not expose stored record values.' => 'بازرسی فرادادهٔ جدول‌ها، ستون‌ها و ایندکس‌های این نصب با اتصال دیتابیس وردپرس. در چندسایتی مدیر ارشد شبکه لازم است. مقادیر ذخیره‌شدهٔ رکوردها نمایش داده نمی‌شوند.',
	'Sensitive Database Read' => 'خواندن حساس دیتابیس',
	'Read exact raw installation database values, including possible credentials and private plugin data. Database Inspection and administrator authority are also required. This grants no database writes.' => 'خواندن دقیق مقادیر خام دیتابیس نصب، شامل اطلاعات ورود احتمالی و داده‌های خصوصی افزونه‌ها. بازرسی دیتابیس و اختیار مدیریت نیز لازم است. این مجوز اجازهٔ نوشتن در دیتابیس نمی‌دهد.',
	'The WordPress connection needs a nonempty installation prefix and identifier-placeholder support.' => 'اتصال وردپرس باید پیشوند نصب غیرخالی و پشتیبانی از جای‌گذار شناسه داشته باشد.',
	'Select a supported database read action.' => 'یکی از عملیات پشتیبانی‌شدهٔ خواندن دیتابیس را انتخاب کنید.',
	'Select a table belonging to this WordPress installation prefix.' => 'جدولی متعلق به پیشوند همین نصب وردپرس انتخاب کنید.',
	'The exact base table is unavailable to the WordPress database account. Views and other databases are not supported.' => 'جدول اصلی با نام دقیق برای حساب دیتابیس وردپرس در دسترس نیست. Viewها و دیتابیس‌های دیگر پشتیبانی نمی‌شوند.',
	'Select exact columns from the current table schema.' => 'نام دقیق ستون‌ها را از ساختار فعلی جدول انتخاب کنید.',
	'after_id requires one integer primary key and cannot be combined with an offset.' => 'after_id به یک کلید اصلی عدد صحیح نیاز دارد و همراه offset قابل استفاده نیست.',
	'The exact key must match one current record. Refresh the key; absent or duplicate records cannot supply a cell window.' => 'کلید دقیق باید با یک رکورد فعلی مطابقت داشته باشد. کلید را تازه‌سازی کنید؛ رکورد ناموجود یا تکراری نمی‌تواند بخش مقدار را ارائه کند.',
	'The database value changed. Restart with its current hash; do not concatenate windows from different values.' => 'مقدار دیتابیس تغییر کرده است. خواندن را با هش فعلی از نو شروع کنید؛ بخش‌های مقادیر متفاوت را به هم متصل نکنید.',
	'The requested byte offset is beyond the current value. Refresh its length and hash.' => 'موقعیت بایت درخواستی خارج از مقدار فعلی است. طول و هش آن را تازه‌سازی کنید.',
	'The WordPress database account could not complete this read. Check table/metadata privileges, database availability and server diagnostics. Raw query text and stored values are omitted.' => 'حساب دیتابیس وردپرس نتوانست خواندن را کامل کند. مجوز جدول و فراداده، دسترس‌پذیری دیتابیس و گزارش‌های سرور را بررسی کنید. متن خام کوئری و مقادیر ذخیره‌شده در خطا نمایش داده نمی‌شوند.',
);
