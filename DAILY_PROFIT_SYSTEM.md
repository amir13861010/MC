# سیستم پردازش Daily Profit

این سیستم به صورت خودکار `dailyProfit` هر روز را از فایل‌های JSON کاربران می‌خواند و آن را در `deposit_balance` کاربر ضرب می‌کند.

## ویژگی‌های جدید

- **مدت اعتبار**: هر trade به مدت 30 روز فعال است
- **پردازش خودکار**: فقط trade های فعال پردازش می‌شوند
- **ردیابی پردازش**: آخرین زمان پردازش ثبت می‌شود
- **غیرفعال‌سازی خودکار**: trade های منقضی شده به صورت خودکار غیرفعال می‌شوند
- **تمدید خودکار**: trade های منقضی شده به صورت خودکار تمدید می‌شوند

## نحوه کارکرد

1. **فایل‌های JSON**: هر کاربر یک فایل JSON در مسیر `storage/app/private/trades/{user_id}.json` دارد
2. **ساختار فایل**: هر فایل شامل `dailyReports` است که برای هر روز یک `dailyProfit` دارد
3. **محاسبه**: `dailyProfit` (به درصد) × `deposit_balance` = سود روزانه
4. **به‌روزرسانی**: سود روزانه به فیلد `gain_profit` کاربر اضافه می‌شود

## کامپوننت‌های سیستم

### 1. Job (ProcessDailyProfitJob)
- **مسیر**: `app/Jobs/ProcessDailyProfitJob.php`
- **وظیفه**: پردازش سود روزانه برای همه کاربران
- **اجرا**: هر روز ساعت 1:00 صبح

### 2. Command (ProcessDailyProfitCommand)
- **مسیر**: `app/Console/Commands/ProcessDailyProfitCommand.php`
- **دستور**: `php artisan trade:process-daily-profit`
- **گزینه‌ها**: `--date=YYYY-MM-DD` (اختیاری)

### 3. Command (DeactivateExpiredTradesCommand)
- **مسیر**: `app/Console/Commands/DeactivateExpiredTradesCommand.php`
- **دستور**: `php artisan trade:deactivate-expired`
- **وظیفه**: غیرفعال کردن trade های منقضی شده

### 4. Command (CheckExpiredTradeFiles)
- **مسیر**: `app/Console/Commands/CheckExpiredTradeFiles.php`
- **دستور**: `php artisan trade:check-expired`
- **وظیفه**: بررسی و تمدید خودکار trade های منقضی شده

### 4. API Endpoints
- **مسیر**: `POST /api/trade/{user_id}/process-daily-profit`
- **پارامترها**: 
  - `user_id`: شناسه کاربر
  - `date`: تاریخ (اختیاری، پیش‌فرض امروز)

- **مسیر**: `GET /api/trades/active`
- **وظیفه**: دریافت لیست trade های فعال

## نحوه استفاده

### اجرای دستی برای همه کاربران
```bash
php artisan trade:process-daily-profit
```

### اجرای دستی برای تاریخ خاص
```bash
php artisan trade:process-daily-profit --date=2025-07-01
```

### غیرفعال کردن trade های منقضی شده
```bash
php artisan trade:deactivate-expired
```

### تمدید خودکار trade های منقضی شده
```bash
php artisan trade:check-expired
```

### اجرای API برای کاربر خاص
```bash
curl -X POST "http://your-domain/api/trade/MC-016104/process-daily-profit" \
     -H "Content-Type: application/json" \
     -d '{"date": "2025-07-01"}'
```

### دریافت لیست trade های فعال
```bash
curl -X GET "http://your-domain/api/trades/active"
```

### تست سیستم
```bash
php test_daily_profit.php
```

## Cron Jobs

سیستم به صورت خودکار هر روز اجرا می‌شود:

```php
// در app/Console/Kernel.php

// غیرفعال کردن trade های منقضی شده - ساعت 00:30
$schedule->command('trade:deactivate-expired')
    ->daily()
    ->at('00:30')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/deactivate-expired.log'));

// تمدید خودکار trade های منقضی شده - ساعت 00:45
$schedule->command('trade:check-expired')
    ->daily()
    ->at('00:45')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/check-expired.log'));

// پردازش سود روزانه - ساعت 01:00
$schedule->command('trade:process-daily-profit')
    ->daily()
    ->at('01:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/daily-profit.log'));
```

## لاگ‌ها

- **فایل لاگ daily profit**: `storage/logs/daily-profit.log`
- **فایل لاگ deactivate expired**: `storage/logs/deactivate-expired.log`
- **فایل لاگ check expired**: `storage/logs/check-expired.log`
- **محتوای لاگ**: جزئیات پردازش هر کاربر و خطاها

## نمونه خروجی API

```json
{
    "message": "Daily profit processed successfully",
    "data": {
        "user_id": "MC-016104",
        "date": "2025-07-01",
        "daily_profit_percent": 0.1275,
        "deposit_balance": 1000.00,
        "daily_profit_amount": 1.275,
        "new_gain_profit": 101.275
    }
}
```

## نکات مهم

1. **فایل‌های JSON**: باید در مسیر `storage/app/private/trades/` قرار داشته باشند
2. **فرمت تاریخ**: باید به صورت `YYYY-MM-DD` باشد
3. **محاسبه درصد**: `dailyProfit` به صورت درصد است (مثل 0.1275 برای 0.1275%)
4. **به‌روزرسانی**: فقط فیلد `gain_profit` به‌روزرسانی می‌شود
5. **مدت اعتبار**: هر trade به مدت 30 روز فعال است
6. **پردازش خودکار**: فقط trade های فعال پردازش می‌شوند
7. **خطاها**: اگر فایل یا تاریخ موجود نباشد، خطا برمی‌گردد

## عیب‌یابی

### مشکل: فایل JSON پیدا نمی‌شود
- بررسی کنید فایل در مسیر `storage/app/private/trades/` وجود دارد
- نام فایل باید `{user_id}.json` باشد

### مشکل: تاریخ در فایل موجود نیست
- بررسی کنید تاریخ در `dailyReports` فایل JSON موجود است
- فرمت تاریخ باید `YYYY-MM-DD` باشد

### مشکل: کاربر پیدا نمی‌شود
- بررسی کنید `user_id` در جدول `users` موجود است
- بررسی کنید فیلد `deposit_balance` مقدار دارد

### مشکل: trade غیرفعال است
- بررسی کنید trade منقضی نشده باشد
- بررسی کنید فیلد `is_active` برابر `true` باشد
- بررسی کنید `expires_at` در آینده باشد 