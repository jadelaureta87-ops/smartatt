# attendanceqr

## Kiosk password

The scanner is locked behind a kiosk password, and `api.php` rejects scans without the authenticated kiosk session. Before deploying online, set the `KIOSK_PASSWORD` environment variable to a strong private password. The built-in `BNAS-Kiosk-Change-Me` value is only a local fallback and must not be used in production.