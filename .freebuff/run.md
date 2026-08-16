# Preview run doc — TADE PHARMACY

Plain PHP 8 project (no package.json / npm). Serves from the repository root with the built-in PHP dev server. SQLite DB at `data/pharmacy.db` — it is created and auto-migrated by `db.php::initDB()` / `initPurchaseModuleSchema()` on the first request, so a fresh checkout needs no manual setup.

## Reproduce the artifacts

There are no build steps or generated artifacts, and no env files to copy — configuration is stored in the `settings` table inside the SQLite DB (seeded with defaults). Just make sure PHP is on the PATH:

- PHP: `C:\Program Files\PHP\current\php.exe` (>= 8.1; `php -l` is used for linting)

The default admin login is seeded automatically: `admin` / `admin123`.

## Run the server

Detached (outlives the terminal), on port 8000:

```powershell
powershell -NoProfile -Command "$p = Start-Process -FilePath 'C:\Program Files\PHP\current\php.exe' -ArgumentList '-S','127.0.0.1:8000' -WorkingDirectory 'C:\Users\11\Desktop\Projects\TADE PHARMACY' -RedirectStandardOutput '<log>' -RedirectStandardError '<log>.err' -WindowStyle Hidden -PassThru; $p.Id"
```

Notes:
- The `-RedirectStandardOutput` call can hang the invoking shell even though the server started — verify with `netstat -ano | grep ':8000'` / `Get-Process php` and `curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8000/index.php` (expect `302`, a redirect to login).
- PHP writes the dev-server log lines to stderr, so check `<log>.err` for startup diagnostics.
- If port 8000 is taken, pick another free port and change the `-S` argument.
