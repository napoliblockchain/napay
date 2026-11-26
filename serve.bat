@echo off

:: Update Service Worker
call npm run update-sw

:start
:: PHP8
yii serve 0.0.0.0 -p 30201

