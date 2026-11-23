# SMS-Awarie v3.1.1 — pełny pakiet instalacyjny Windows 10/11

**3.1.1 (poprawki):** zobacz `CHANGELOG.md`. Aktualizacja z 3.1: podmiana plików przez `scripts\10-install-files.ps1 -Apply` (schemat bazy migruje się sam), przebudowa frontendu, opcjonalnie `scripts\16-install-backup-task.ps1 -Apply`. Od tej wersji odebrane SMS-y są **usuwane z modemu po zapisie w bazie** (wyłączenie: `"delete_after_import": false` w `data\config.json`).

**Wydanie: instalator źródłowy / kandydat do testów integracyjnych.** Zawiera scalony kod v3.0 i v3.1, frontend Next.js, API PHP, odbiornik Huawei E5373, Watchdog v3, obsługę grup i wielu odbiorców, skrypty Windows oraz dokumentację.

Nie zawiera instalatorów XAMPP/Node.js, `node_modules`, gotowego `.next`, `package-lock.json`, rzeczywistych haseł, danych użytkownika ani certyfikatów HTTPS. Wymaga instalacji zależności i `npm install`/utworzenia lockfile, a następnie weryfikacji `npm ci`, `npm run typecheck` i `npm run build` na docelowym Windows. Nie deklarujemy pełnej walidacji środowiska Windows.

**Zacznij od `docs/PIERWSZA-INSTALACJA.md`.**

Układ: `payload/C-SMS-Awarie/` -> `C:\SMS-Awarie\`, `payload/C-SMS-Awarie-Web/` -> `C:\SMS-Awarie-Web\`; `scripts/` -> uruchamiaj z rozpakowanego katalogu. Skrypty z `-Apply` zmieniają system; najpierw wykonaj wariant bez `-Apply`.

Ochrona: `W_TRAKCIE` i `NIEPEWNY` nie są ponawiane automatycznie. Brak migracji danych wymaganej dla nowej instalacji; `15-init-fresh.ps1` tworzy schemat. Nie uruchamiaj wielu odbiorników ani wielu Watchdogów.

## Repozytorium

Kod źródłowy: prywatne repozytorium `WojBond/sms-awarie` (lokalnie `D:\Dev\sms-awarie`). Wersja: `APP_VERSION` w `payload/C-SMS-Awarie/common.php`, historia w `CHANGELOG.md`, tagi `vX.Y.Z`.
Paczkę wdrożeniową buduje `tools\build-package.ps1` (nazwa `sms-awarie-vX.Y.Z.zip` z wersji w kodzie). Do repozytorium nie trafiają bazy, `config.json`, logi, `node_modules` ani `.next` (patrz `.gitignore`).
