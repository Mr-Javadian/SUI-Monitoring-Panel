<div align="center" dir="rtl">

# 📡 SUI-Monitoring-Panel

**داشبورد زندهٔ تلگرام برای پنل‌های [S-UI](https://github.com/alireza0/s-ui)**
برای هر سرور یک پیام که خودش آپدیت می‌شود: CPU، RAM، دیسک، ترافیک، کاربران، کاربران آنلاین و هشدارهای لحظه‌ای.

[![Version](https://img.shields.io/badge/version-1.0.0-blue?style=flat-square)](CHANGELOG.md)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green?style=flat-square)](LICENSE)

[English](README.md) · **فارسی**

</div>

<div dir="rtl">

---

## ✨ چرا این پروژه؟

برای چک کردن چند پنل S-UI باید چند پنل وب را باز کنید. **SUI-Monitoring-Panel** همه چیز را در تلگرام شما جمع می‌کند. برای هر پنل **یک پیام** ساخته می‌شود که هر چند ثانیه خودش را ویرایش می‌کند، پس چت شلوغ نمی‌شود. اگر مشکلی پیش بیاید، یک پیام هشدار جدا برایتان ارسال می‌شود.

- 🔄 **پیام خودکار:** هر سرور یک پیام دارد که درجا ویرایش می‌شود.
- 🖥️ **چند سروره:** با یک ربات هر تعداد پنل را مانیتور کنید.
- 📊 **داشبورد کامل:** نوار CPU/RAM/SWAP/دیسک، سرعت I/O دیسک، آپ‌تایم، IPها، وضعیت هسته و آمار پنل.
- 👥 **اطلاعات کاربران:** فعال/غیرفعال/منقضی، اتصال‌های آنلاین، کاربران آنلاین یکتا و لیست N کاربر پرمصرف.
- 🚨 **هشدار هوشمند:** CPU/RAM/دیسک بالا، خاموش شدن هسته، **قطع بودن سرور** (و پیام بازگشت)، همراه با cooldown.
- 🧙 **نصب با یک دستور:** ویزارد تعاملی که توکن‌ها را می‌پرسد، اتصال‌ها را تست می‌کند و سرویس systemd می‌سازد.
- ⬆️ **آپدیت امن:** `sui-monitor update` تنظیمات شما را نگه می‌دارد و اگر نسخهٔ جدید مشکل داشته باشد **خودکار برمی‌گردد**.
- 🌍 **پشتیبانی از پروکسی:** برای سرورهایی که تلگرام در آن‌ها فیلتر است.

## 🚀 نصب سریع

روی سروری که قرار است ربات روی آن اجرا شود (لزومی ندارد همان سرور پنل باشد)، با کاربر **root** بزنید:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/Mr-Javadian/SUI-Monitoring-Panel/main/install.sh)
```

اسکریپت این کارها را انجام می‌دهد:

1. اگر PHP 8.1+‎، git و curl نصب نباشند، نصبشان می‌کند.
2. پروژه را در `/opt/sui-monitoring-panel` دانلود می‌کند.
3. **توکن ربات تلگرام** را می‌پرسد و **Chat ID** را خودکار تشخیص می‌دهد.
4. **آدرس** و **توکن API** هر پنل را می‌پرسد و همان لحظه اتصال را تست می‌کند.
5. چند تنظیم ساده می‌پرسد (فاصلهٔ آپدیت، هشدارها، تعداد کاربران پرمصرف).
6. پیام تست می‌فرستد، دستور `sui-monitor` را نصب می‌کند و سرویس را اجرا می‌کند.

> **قبل از شروع به این‌ها نیاز دارید:**
> 1. توکن ربات از [@BotFather](https://t.me/BotFather) (دستور `/newbot`) و سپس زدن **Start** روی ربات.
> 2. **توکن API** از هر پنل S-UI (از داخل پنل وب ساخته می‌شود).
> 3. آدرس پنل، مثلاً `https://panel.example.com:2095/app/`

### پیش‌نیازها

| مورد | توضیح |
|---|---|
| لینوکس با **systemd** | دبیان/اوبونتو و خانوادهٔ RHEL (بدون systemd هم می‌شود دستی اجرا کرد) |
| **PHP ‎≥ 8.1** | با افزونه‌های `curl`، `mbstring`، `json` (نصب‌کننده خودش مدیریت می‌کند) |
| دسترسی HTTPS خروجی | به پنل‌ها و به `api.telegram.org` (یا از طریق `telegram.proxy`) |

## 🔑 آدرس پنل و توکن API

- **آدرس:** پروتکل + دامنه/IP + پورت + مسیر پنل. مسیر پیش‌فرض `/app/` است، پس API در `/app/apiv2` قرار دارد. به نصب‌کننده فقط `https://panel.example.com:2095/app/` را بدهید؛ خودش `apiv2` را اضافه می‌کند. در فایل `config.php` فرم کامل استفاده می‌شود: `https://panel.example.com:2095/app/apiv2`
- **توکن:** داخل پنل S-UI یک توکن API بسازید و هنگام نصب وارد کنید. ربات فقط اطلاعات را **می‌خواند**.
- **گواهی self-signed دارید؟** به سؤال «Verify the TLS certificate?» جواب `n` بدهید (`verify_ssl => false`).

## 🛠️ مدیریت ربات

بعد از نصب، دستور `sui-monitor` در کل سیستم در دسترس است:

| دستور | کار |
|---|---|
| `sui-monitor` | منوی تعاملی |
| `sui-monitor status` | وضعیت سرویس و سرورها |
| `sui-monitor logs` | نمایش زندهٔ لاگ |
| `sui-monitor update` | آپدیت به آخرین نسخه (تنظیمات حفظ می‌شود، در صورت خطا برگشت خودکار) |
| `sui-monitor add-server` | اضافه کردن پنل جدید (همراه با تست اتصال) |
| `sui-monitor remove-server` | حذف یک پنل |
| `sui-monitor reconfigure` | تغییر توکن ربات / چت / تنظیمات پایه |
| `sui-monitor edit` | ویرایش `config.php`، اعتبارسنجی و ری‌استارت |
| `sui-monitor test` | تست کامل و ارسال پیام آزمایشی |
| `sui-monitor start \| stop \| restart` | کنترل سرویس |
| `sui-monitor uninstall` | حذف کامل (با امکان نگه داشتن بکاپ تنظیمات) |

### آپدیت

```bash
sudo sui-monitor update
```

آپدیت‌کننده آخرین کد را می‌گیرد، تغییرات را نشان می‌دهد، نسخهٔ جدید را با **تنظیمات خودتان** اعتبارسنجی می‌کند و سرویس را ری‌استارت می‌کند. اگر اعتبارسنجی ناموفق باشد، نسخهٔ قبلی خودکار بازمی‌گردد. فایل `config/config.php` و وضعیت ذخیره‌شده دست نمی‌خورند (از تنظیمات هر بار بکاپ زمان‌دار گرفته می‌شود و سه بکاپ آخر نگه داشته می‌شود).

## ⚙️ تنظیمات

همهٔ تنظیمات در **`config/config.php`** است (از روی [`config/config.example.php`](config/config.example.php) ساخته می‌شود). هر گزینه داخل خود فایل با کامنت توضیح داده شده است: کاربرد، مقادیر مجاز، مقدار پیش‌فرض و مثال.

| بخش | چه چیزی را کنترل می‌کند |
|---|---|
| `telegram` | توکن ربات، Chat ID، پروکسی و آدرس سفارشی Bot API |
| `servers` | پنل‌ها: نام، آدرس API، توکن، بررسی TLS |
| `display` | روشن/خاموش کردن هر بخش داشبورد |
| `top_users` | تعداد کاربران پرمصرف، عرض نوار، نوع مرتب‌سازی، حداقل مصرف |
| `monitor` | فاصلهٔ آپدیت، timeout، تعداد تلاش مجدد، مدت کش |
| `alerts` | کلید اصلی، آستانه‌ها، خاموشی هسته، قطع بودن سرور، cooldown |
| `storage`, `logging` | پوشهٔ داده، سطح لاگ، چرخش لاگ |

اعمال تغییرات:

```bash
sudo sui-monitor edit
# یا دستی:
sudo nano /opt/sui-monitoring-panel/config/config.php
sudo systemctl restart sui-monitor
```

نمونهٔ کانفیگ حداقلی (بقیهٔ گزینه‌ها پیش‌فرض می‌شوند):

```php
<?php
return [
    'telegram' => ['bot_token' => '123456789:AA...', 'chat_id' => -1001234567890],
    'servers'  => [
        'frankfurt' => ['base_url' => 'https://de.example.com:2095/app/apiv2', 'token' => '...'],
        'tokyo'     => ['base_url' => 'https://jp.example.com:2095/app/apiv2', 'token' => '...', 'verify_ssl' => false],
    ],
];
```

## 🚨 هشدارها

هشدارها **پیام‌های جدا** هستند تا گوشی‌تان واقعاً اعلان بدهد.

| هشدار | شرط | تنظیم |
|---|---|---|
| 🔥 CPU بالا | CPU ≥ آستانه | `alerts.cpu_threshold` (پیش‌فرض ۸۵٪) |
| 🧠 RAM بالا | RAM ≥ آستانه | `alerts.ram_threshold` (پیش‌فرض ۸۵٪) |
| 💿 دیسک پر | دیسک ≥ آستانه | `alerts.disk_threshold` (پیش‌فرض ۹۰٪) |
| 💀 هسته خاموش | sing-box متوقف است (و در حالت maintenance نیست) | `alerts.core_down` |
| 📡 سرور در دسترس نیست | چند بار آپدیت ناموفق پشت سر هم؛ بعد از برگشتن پیام `✅ RECOVERED` | `alerts.offline`، `offline_after_failures`، `recovery_notice` |

برای جلوگیری از اسپم، هشدار تکراری از یک نوع برای یک سرور تا `alerts.cooldown_seconds` (پیش‌فرض ۵ دقیقه) ارسال نمی‌شود. مقدار آستانه `0` همان هشدار را غیرفعال می‌کند.

## 🧩 نصب دستی (بدون نصب‌کننده)

```bash
sudo apt install -y git php-cli php-curl php-mbstring     # نیاز به PHP 8.1+
git clone https://github.com/Mr-Javadian/SUI-Monitoring-Panel.git
cd SUI-Monitoring-Panel
cp config/config.example.php config/config.php && nano config/config.php
php bin/monitor.php --check      # اعتبارسنجی تنظیمات و تست تلگرام و همهٔ پنل‌ها
php bin/monitor.php              # اجرا در پیش‌زمینه (توقف با Ctrl+C)
```

گزینه‌های خط فرمان: ‎`--check` · `--test-message` · `--validate` · `--once` · `--config=FILE` · `--version` · `--help`

## 🩺 عیب‌یابی

اول `sudo sui-monitor test` را اجرا کنید؛ دقیقاً می‌گوید کدام بخش مشکل دارد.

| علامت | علت و راه‌حل |
|---|---|
| `Telegram: Unauthorized` | توکن ربات اشتباه است؛ دوباره از @BotFather کپی کنید. |
| `Could not post to chat` / `chat not found` | در چت خصوصی **Start** را بزنید یا ربات را به عنوان **ادمین** به گروه/کانال اضافه کنید. |
| تلگرام در دسترس نیست / timeout | تلگرام روی آن سرور فیلتر است → `telegram.proxy` را تنظیم کنید (مثلاً `socks5h://127.0.0.1:1080`). |
| پنل: `Invalid token` | توکن API جدید در پنل بسازید و در تنظیمات سرور جایگزین کنید. |
| پنل: `HTTP 404` | مسیر پنل اشتباه است. پیش‌فرض `/app/apiv2` است؛ از *Path* در تنظیمات پنل استفاده کنید. |
| پنل: خطای SSL | گواهی self-signed است → برای آن سرور `verify_ssl => false` بگذارید. |
| داشبورد آپدیت نمی‌شود | `sui-monitor logs`. تلگرام سرعت ویرایش را محدود می‌کند؛ برای سرورهای زیاد `monitor.interval` را بیشتر کنید. |
| پیام داشبورد دوباره ساخته شد | فقط وقتی پیش می‌آید که پیام قبلی را پاک کرده یا اسم سرور را عوض کرده باشید. |
| سرویس بالا نمی‌آید | `journalctl -u sui-monitor -n 50`. کد خروج ۲ یعنی تنظیمات نامعتبر است (پیام، گزینهٔ دقیق را مشخص می‌کند). |

## 📁 ساختار پروژه

```text
SUI-Monitoring-Panel/
├── bin/monitor.php            # نقطهٔ ورود
├── src/                       # کلاس‌های PSR-4 با namespace ‏SuiMonitor\
├── config/config.example.php  # قالب کاملاً مستند تنظیمات
├── scripts/config-tool.php    # ویرایشگر تنظیمات (بدون پاک شدن کامنت‌ها)
├── systemd/sui-monitor.service
├── install.sh                 # نصب/آپدیت/مدیریت  (-> دستور sui-monitor)
├── tests/                     # سرور ساختگی S-UI و تلگرام برای تست محلی
└── VERSION · CHANGELOG.md · LICENSE · SECURITY.md · composer.json
```

## 🤝 مشارکت

Issue و Pull Request خوش‌آمد است. لطفاً چیدمان داشبورد را ثابت نگه دارید، تغییرات را در CHANGELOG بنویسید و هیچ توکن واقعی‌ای commit نکنید.

## 📄 لایسنس

[MIT](LICENSE) © Mr-Javadian

</div>
