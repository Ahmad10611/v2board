<img src="https://avatars.githubusercontent.com/u/56885001?s=200&v=4" alt="logo" width="130" height="130" align="right"/>

[![](https://img.shields.io/badge/TgChat-@UnOfficialV2board讨论-blue.svg)](https://t.me/unofficialV2board)

## Backend هایی که توسط این شاخه پشتیبانی می شوند
 
 - [修改版XrayR](https://github.com/wyx2685/XrayR)
 - [修改版V2bX](https://github.com/wyx2685/V2bX)
 - [V2bX](https://github.com/InazumaV/V2bX)

## مراحل مهاجرت اصلی

برای انتقال فایل های پانل مراحل زیر را دنبال کنید：

    git remote set-url origin https://github.com/wyx2685/v2board  
    git checkout master  
    ./update.sh  


مراحل زیر را دنبال کنید تا کش تنظیمات را تازه کنید و صف را مجددا راه اندازی کنید:

    php artisan config:clear
    php artisan config:cache
    php artisan horizon:terminate

در نهایت وارد پس‌زمینه شده و تم را دوباره ذخیره کنید: پیکربندی تم-تنظیمات تم-OK

# **V2Board**

- PHP7.3+
- Composer
- MySQL5.5+
- Redis
- Laravel

## Demo
[Demo](https://demo.v2board.com)

## Document
[Click](https://v2board.com)

## Sponsors
Thanks to the open source project license provided by [Jetbrains](https://www.jetbrains.com/)

## Community
🔔Telegram Channel: [@v2board](https://t.me/v2board)  

## How to Feedback
Follow the template in the issue to submit your question correctly, and we will have someone follow up with you.
