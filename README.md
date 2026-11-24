# SMS-Awarie

System zgłaszania awarii SMS-em: modem Huawei E5373 odbiera SMS, odbiornik PHP zakłada zgłoszenie, rozsyła powiadomienia do grup i potwierdzenie do zgłaszającego. Całość działa w standardowym folderze XAMPP `<XAMPP>\htdocs\sms-awarie` (XAMPP na dowolnym dysku, np. `D:\xampp`), panel pod `http://serwer/sms-awarie/`.

**Wersja:** `APP_VERSION` w `htdocs/sms-awarie/config.php` (plakietka w prawym dolnym rogu panelu). Historia zmian: `CHANGELOG.md`.

## Składniki

| Element | Gdzie | Rola |
|---|---|---|
| `htdocs/sms-awarie/receiver.php` | `<XAMPP>\htdocs\sms-awarie\` | Odbiornik: czyta SMS z modemu, zapisuje do SQLite, wysyła kolejkę. Jedna instancja. |
| `htdocs/sms-awarie/watchdog.ps1` | `<XAMPP>\htdocs\sms-awarie\` | Co minutę (Harmonogram zadań) pilnuje odbiornika i restartuje go z limitem 3/h. |
| `htdocs/sms-awarie/backup.php` | `<XAMPP>\htdocs\sms-awarie\` | Codzienna kopia bazy z retencją. |
| `htdocs/sms-awarie/index.php`, `htdocs/sms-awarie/assets/` | `http://serwer/sms-awarie/` | Panel WWW. Reszta plików jest zablokowana przez `.htaccess`. |
| `htdocs/sms-awarie/db.php` | | Połączenie z bazą: SQLite (domyślnie) albo MS SQL Server (`docs/MSSQL.md`). |
| `htdocs/sms-awarie/data/` | `<XAMPP>\htdocs\sms-awarie\data\` | Baza `awarie.sqlite`, `config.json`, heartbeat, kopie. Poza repozytorium. |

Wymagania: Windows 10/11, XAMPP z PHP 8.2+ i `AllowOverride All` dla htdocs (domyślne w XAMPP) (`pdo_sqlite`, `curl`, `SimpleXML`), modem w sieci hosta. **Bez Node.js i npm.**

## Instalacja i aktualizacja

Zobacz `docs/INSTALACJA.md`. W skrócie, w PowerShell jako administrator z rozpakowanej paczki:

```powershell
.\scripts\install.ps1          # podgląd
.\scripts\install.ps1 -Apply   # instalacja / aktualizacja (dane zostają)
.\scripts\install.ps1 -Xampp D:\xampp -Apply   # gdy XAMPP nie zostanie wykryty
```

## Rozwój

- Kod: prywatne repozytorium `WojBond/sms-awarie`, lokalnie `D:\Dev\sms-awarie`.
- Lokalny test panelu: `php -S 127.0.0.1:8080 -t htdocs/sms-awarie` (wbudowany serwer nie czyta `.htaccess` – tylko do testów lokalnych) (baza powstanie w `htdocs/sms-awarie/data/`).
- Paczka wdrożeniowa: `.\tools\build-package.ps1 -Out D:\AppDeploy\Projekty_HTML\sms-awarie` → `sms-awarie-vX.Y.Z.zip` (wersja z kodu).
- Przy każdej zmianie: podbij `APP_VERSION`, dopisz `APP_CHANGELOG` i `CHANGELOG.md`, tag `vX.Y.Z`.
