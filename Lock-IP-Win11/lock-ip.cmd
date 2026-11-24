@echo off
rem Right-click -> "Run as administrator".  Examples:
rem   lock-ip.cmd                      lock the IP the PC has now
rem   lock-ip.cmd -IP 192.168.1.50     lock a chosen IP
rem   lock-ip.cmd -Dhcp                back to automatic
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0lock-ip.ps1" %*
pause
