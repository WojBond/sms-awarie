# SMS-Awarie

System zgłaszania awarii SMS-em: modem Huawei E5373 odbiera SMS, odbiornik PHP zakłada zgłoszenie, rozsyła powiadomienia do grup i potwierdzenie do zgłaszającego. Panel WWW w PHP działa w Apache z XAMPP.

**Wersja:** `APP_VERSION` w `app/config.php` (plakietka w prawym dolnym rogu panelu). Historia zmian: `CHANGELOG.md`.

## Składniki

| Element | Gdzie | Rola |
|---|---|---|
| `app/receiver.php` | `C:\SMS-Awarie\` | Odbiornik: czyta SMS z modemu, zapisuje do SQLite, wysyła kolejkę. Jedna instancja. |
| `app/watchdog.ps1` | `C:\SMS-Awarie\` | Co minutę (Harmonogram zadań) pilnuje odbiornika i restartuje go z limitem 3/h. |
| `app/backup.php` | `C:\SMS-Awarie\` | Codzienna kopia bazy z retencją. |
| `app/public/` | `http://serwer/sms-awarie/` | Panel WWW (jedyny katalog widoczny przez Apache). |
| `app/data/` | `C:\SMS-Awarie\data\` | Baza `awarie.sqlite`, `config.json`, heartbeat, kopie. Poza repozytorium. |

Wymagania: Windows 10/11, XAMPP z PHP 8.2+ (`pdo_sqlite`, `curl`, `SimpleXML`), modem w sieci hosta. **Bez Node.js i npm.**

## Instalacja i aktualizacja

Zobacz `docs/INSTALACJA.md`. W skrócie, w PowerShell jako administrator z rozpakowanej paczki:

```powershell
.\scripts\install.ps1          # podgląd
.\scripts\install.ps1 -Apply   # instalacja / aktualizacja (dane zostają)
```

## Rozwój

- Kod: prywatne repozytorium `WojBond/sms-awarie`, lokalnie `D:\Dev\sms-awarie`.
- Lokalny test panelu: `php -S 127.0.0.1:8080 -t app/public` (baza powstanie w `app/data/`).
- Paczka wdrożeniowa: `.\tools\build-package.ps1 -Out D:\AppDeploy\Projekty_HTML\sms-awarie` → `sms-awarie-vX.Y.Z.zip` (wersja z kodu).
- Przy każdej zmianie: podbij `APP_VERSION`, dopisz `APP_CHANGELOG` i `CHANGELOG.md`, tag `vX.Y.Z`.
