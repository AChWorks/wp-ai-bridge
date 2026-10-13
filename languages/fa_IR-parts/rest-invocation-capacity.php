<?php
/** Persian labels for separately configured high-trust REST JSON input. */
return array(
	'Registered REST request input capacity' => 'ظرفیت ورودی درخواست REST ثبت‌شده',
	'This is one high-trust local registered REST invocation JSON input, not the total transferable file capacity. It does not enable REST invocation, change sensitive route and parameter exclusions, or bypass native WordPress permissions. Its effective budget cannot exceed the separately configured incoming MCP request budget.' => 'این تنظیم حجم ورودی JSON یک درخواست محلی REST ثبت‌شده با سطح اعتماد بالا را محدود می‌کند، نه حجم کل فایل قابل‌انتقال. هیچ دسترسی اجرای REST را فعال نمی‌کند، محدودیت مسیرها یا پارامترهای حساس را تغییر نمی‌دهد و مجوزهای بومی وردپرس را دور نمی‌زند. ظرفیت مؤثر آن از سقف مستقل پیام ورودی MCP بیشتر نمی‌شود.',
	'Maximum registered REST JSON input (KiB)' => 'حداکثر ورودی JSON اجرای REST ثبت‌شده (KiB)',
	'Default 16 KiB. Requested: %1$s KiB; effective input allowance: %2$s bytes; current incoming MCP request allowance: %3$s bytes. Host, client, PHP memory and provider JSON validation remain independently binding. Raise the incoming MCP limit separately when needed.' => 'پیش‌فرض ۱۶ KiB. درخواست: %1$s KiB؛ ظرفیت مؤثر ورودی: %2$s بایت؛ سقف فعلی پیام MCP ورودی: %3$s بایت. محدودیت‌های هاست، کلاینت، حافظه PHP و اعتبارسنجی JSON ارائه‌دهنده مستقل هستند. در صورت نیاز سقف MCP ورودی را جداگانه افزایش دهید.',
	'Save registered REST input capacity' => 'ذخیره ظرفیت ورودی REST ثبت‌شده',
	'Registered REST input budget must be a positive whole number of KiB within the current PHP integer range. The invalid value was not saved.' => 'ظرفیت ورودی REST ثبت‌شده باید عدد صحیح و مثبت بر حسب KiB در محدودهٔ صحیح PHP باشد. مقدار نامعتبر ذخیره نشد.',
);
