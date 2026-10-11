<?php
/**
 * Persian messages for truthful connection and Core update diagnostics.
 *
 * @package WP_AI_Bridge
 */
return array(
	'These are local configuration observations, not a live client connection check. Configure a compatible authenticated OAuth/MCP client using the endpoint shown below.' => 'این اطلاعات فقط وضعیت پیکربندی محلی را نشان می‌دهند و بررسی اتصال زندهٔ کلاینت نیستند. برای اتصال، نشانی MCP زیر را در کلاینت سازگار و احراز هویت‌شده وارد کنید.',
	'Client setup:' => 'تنظیم کلاینت:',
	'After resolving local prerequisites, configure the MCP URL in your client, choose OAuth, authenticate with WordPress, then rescan its tools. Successful OAuth does not grant WordPress or Bridge permissions.' => 'پس از رفع پیش‌نیازهای محلی، نشانی MCP را در کلاینت وارد کنید، OAuth را انتخاب کنید، از طریق وردپرس احراز هویت کنید و ابزارها را دوباره اسکن کنید. OAuth به‌تنهایی مجوزهای وردپرس یا Bridge را اعطا نمی‌کند.',
	'WordPress 6.9 or newer is required.' => 'وردپرس نسخهٔ ۶.۹ یا جدیدتر لازم است.',
	'Unavailable: check WordPress version and Abilities API loading.' => 'در دسترس نیست: نسخهٔ وردپرس و بارگذاری Abilities API را بررسی کنید.',
	'Available (version not reported)' => 'در دسترس است (نسخه گزارش نشده است)',
	'Unavailable: install or activate the official MCP Adapter.' => 'در دسترس نیست: MCP Adapter رسمی را نصب یا فعال کنید.',
	'MCP URL scheme' => 'پروتکل نشانی MCP',
	'HTTPS configured; public reachability not verified' => 'نشانی HTTPS تنظیم شده است؛ دسترسی عمومی تأیید نشده است',
	'HTTPS not configured for this MCP URL' => 'نشانی MCP با HTTPS تنظیم نشده است',
	'Metadata URLs are generated locally; remote fetching and routing have not been checked.' => 'نشانی‌های فراداده به‌صورت محلی ساخته شده‌اند؛ دریافت از راه دور و مسیریابی آن‌ها بررسی نشده است.',
	'External client connection' => 'اتصال کلاینت خارجی',
	'Not verified here: an authenticated client must confirm connection.' => 'در اینجا تأیید نشده است؛ کلاینت احراز هویت‌شده باید اتصال را تأیید کند.',
	'Next diagnostic steps:' => 'گام‌های بعدی عیب‌یابی:',
	'Use supported WordPress and verify that the native Abilities API is loaded.' => 'از نسخهٔ پشتیبانی‌شدهٔ وردپرس استفاده کنید و بارگذاری Abilities API بومی را بررسی کنید.',
	'Install or activate the compatible official MCP Adapter' => 'نصب یا فعال‌کردن MCP Adapter رسمی سازگار',
	'Check WordPress Site URL, REST URL, and reverse-proxy HTTPS configuration.' => 'نشانی سایت وردپرس، نشانی REST و تنظیمات HTTPS پراکسی معکوس را بررسی کنید.',
	'Open Tools → Site Health' => 'بازکردن ابزارها ← سلامت سایت',
	'Run native HTTPS, REST, loopback, and Authorization-header diagnostics where available.' => 'در صورت وجود، عیب‌یابی بومی HTTPS، REST، loopback و هدر Authorization را اجرا کنید.',
	'Site Health diagnostics cannot be verified from this account; ask an authorized administrator to check them if available.' => 'سلامت سایت از طریق این حساب قابل تأیید نیست؛ از مدیر مجاز بخواهید در صورت دسترسی آن را بررسی کند.',
	'If remote discovery fails, inspect /.well-known/ routing, firewall, CDN/proxy rules, and client network access. A local HTTPS URL proves none of these.' => 'اگر شناسایی از راه دور شکست خورد، مسیریابی /.well-known/، دیوارهٔ آتش، قوانین CDN/پراکسی و دسترسی شبکهٔ کلاینت را بررسی کنید. نشانی HTTPS محلی هیچ‌یک را اثبات نمی‌کند.',
	'After connecting, check the exact WordPress user capability and Bridge access group for denied operations; rescan stale tools. Diagnose Gateway routing separately from direct MCP.' => 'پس از اتصال، برای عملیات ردشده، قابلیت دقیق کاربر وردپرس و گروه دسترسی Bridge را بررسی کنید؛ ابزارهای قدیمی را دوباره اسکن کنید. مسیریابی Gateway را جدا از MCP مستقیم عیب‌یابی کنید.',
	'Read WordPress Core Update Status' => 'خواندن وضعیت به‌روزرسانی هستهٔ وردپرس',
	'Reads cached WordPress Core update offers and check freshness; never checks for or installs an update.' => 'پیشنهادهای ذخیره‌شدهٔ به‌روزرسانی هستهٔ وردپرس و تازگی بررسی را می‌خواند؛ هیچ به‌روزرسانی‌ای را بررسی مجدد یا نصب نمی‌کند.',
	'Site Configuration and native WordPress administration permission are required.' => 'گروه پیکربندی سایت و مجوز مدیریت بومی وردپرس لازم است.',
);
