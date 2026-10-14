<?php
/** Persian administrator-facing content capacity policy labels. */
return array(
    'Operation content capacity must be a positive whole number within current PHP memory limits. The previous setting was kept.' => 'ظرفیت محتوای عملیات باید عدد صحیح مثبت و در محدوده حافظه فعلی PHP باشد. تنظیم قبلی حفظ شد.',
    'Installed plugin/theme source preimage and candidate (MiB)' => 'حداکثر اندازه منبع فعلی و نسخه پیشنهادی افزونه/پوسته (MiB)',
    'Default 2 MiB. One complete source file is held for preview, exact write and crash recovery. Increasing needs PHP memory, database and client request/response capacity; it does not bypass Source Editing consent.' => 'پیش‌فرض ۲ MiB. فایل کامل برای پیش‌نمایش، جایگزینی دقیق و بازیابی پس از خرابی نگهداری می‌شود. افزایش آن به حافظه PHP، ظرفیت پایگاه داده و ظرفیت پیام کلاینت نیاز دارد و مجوز ویرایش منبع را دور نمی‌زند.',
    'Registered REST setting JSON value (KiB)' => 'اندازه مقدار JSON تنظیم REST ثبت‌شده (KiB)',
    'Default 1024 KiB. Native WordPress settings authority, provider schemas and incoming MCP message limits still apply.' => 'پیش‌فرض ۱۰۲۴ KiB. مجوزهای بومی تنظیمات وردپرس، طرح‌واره‌های ارائه‌دهنده و محدودیت‌های پیام ورودی MCP همچنان برقرار هستند.',
    'Registered REST setting public schema (KiB)' => 'اندازه طرح‌واره عمومی تنظیم REST ثبت‌شده (KiB)',
    'Default 256 KiB. Oversized schemas remain unavailable rather than being silently truncated or leaking provider defaults.' => 'پیش‌فرض ۲۵۶ KiB. طرح‌واره بزرگ‌تر به‌جای برش خاموش یا افشای مقادیر پیش‌فرض ارائه‌دهنده غیرقابل‌دسترسی اعلام می‌شود.',
    'Public Ability catalog response (KiB)' => 'اندازه پاسخ فهرست عمومی Abilityها (KiB)',
    'Default 1024 KiB. Contract inspection stays read-only; use pagination and check the configured MCP response limit when a larger catalog does not fit.' => 'پیش‌فرض ۱۰۲۴ KiB. بررسی قراردادها فقط خواندنی است؛ در صورت بزرگ بودن فهرست از صفحه‌بندی استفاده کنید و سقف پاسخ MCP را بررسی کنید.',
    'High-trust operation content capacity' => 'ظرفیت محتوای عملیات با اعتماد بالا',
    'These independent site budgets are finite and bounded by the effective PHP memory limit. They do not enable an Ability, alter user permissions, remove native provider constraints or turn one MCP message into a whole-file transfer.' => 'این سقف‌های مستقل سایت محدود و متناسب با حافظه مؤثر PHP هستند. هیچ Ability را فعال نمی‌کنند، مجوزهای کاربران یا محدودیت‌های بومی ارائه‌دهنده را تغییر نمی‌دهند و یک پیام MCP را به انتقال فایل کامل تبدیل نمی‌کنند.',
    'Save operation content capacity' => 'ذخیره ظرفیت محتوای عملیات',
);
