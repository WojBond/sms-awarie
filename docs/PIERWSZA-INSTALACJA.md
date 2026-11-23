# Pierwsza instalacja SMS-Awarie v3.1 — Windows 10/11

## 0. Warunki i ograniczenia
Instalacja na komputerze testowym lub docelowym bez istniejącej produkcji. Windows 10 musi być objęty wsparciem bezpieczeństwa (np. ESU). Wymagane: XAMPP z PHP 8.2+ (PDO SQLite, curl, rozszerzenia wymagane przez kod), Node.js zgodny z Next.js 15, npm, modem Huawei E5373 osiągalny z hosta. Zapewnij stały adres LAN, brak usypiania hosta, konto serwisowe i kopie zapasowe. **Nie udostępniaj panelu przez nieszyfrowany HTTP w sieci firmowej**; port 8090 jest tylko lokalnym testem, a dostęp LAN wymaga HTTPS na reverse proxy i firmowego certyfikatu.

## 1. Rozpakowanie i diagnostyka
Rozpakuj ZIP do `C:\SMS-Awarie-Release-v3.1`. Otwórz PowerShell jako administrator. Uruchom:

```powershell
Set-Location C:\SMS-Awarie-Release-v3.1
.\scripts\01-preflight.ps1
.\scripts\10-install-files.ps1
```

Sprawdź, czy katalogi `C:\SMS-Awarie` i `C:\SMS-Awarie-Web` już istnieją. Jeżeli tak, zachowaj ich kopię i nie traktuj tego jako czystej instalacji.

## 2. Instalacja plików i inicjalizacja SQLite

```powershell
.\scripts\10-install-files.ps1 -Apply
.\scripts\15-init-fresh.ps1
.\scripts\15-init-fresh.ps1 -Apply
```

Skrypt inicjalizacyjny **nie nadpisuje** `data\config.json` ani istniejącej bazy. W nowej instalacji tworzy plik konfiguracyjny z hasłem `CHANGE_ME` i `ack=false`; ustaw prawdziwe hasło modemu, zweryfikuj jego IP i ogranicz ACL pliku konfiguracyjnego. Odbiornik nie wystartuje z hasłem `CHANGE_ME`. Ustawienia `delete_after_import` (domyślnie `true` – usuwanie odebranych SMS z modemu po zapisie w bazie) i `send_per_cycle` (domyślnie 5) można zmienić w `config.json`. Inicjalizacja tworzy tabele, grupę Pojazdy i tabelę odbiorców grupowych. Dodatkowe grupy/prefiksy skonfigurujesz w panelu administratora po uruchomieniu.

## 3. Frontend

```powershell
Set-Location C:\SMS-Awarie-Web\web
npm.cmd install
npm.cmd run typecheck
npm.cmd run build
```

`npm install` pobiera zależności i tworzy `package-lock.json` (nie jest dołączony). Przejrzyj wersje i wyniki audytu zależności; zachowaj wygenerowany lockfile. W powtarzalnych instalacjach używaj `npm.cmd ci`. Jeżeli typecheck/build się nie powiedzie, **nie kontynuuj wdrożenia**.

## 4. Apache i procesy Windows
Uruchom `C:\xampp\apache\bin\httpd.exe -t` i sprawdź moduły `proxy_module`/`proxy_http_module`. Wykonaj z katalogu wydania:

```powershell
.\scripts\12-configure-apache.ps1 -BindIP 127.0.0.1 -Port 8090
.\scripts\12-configure-apache.ps1 -BindIP 127.0.0.1 -Port 8090 -Apply
```

Przed restartem Apache przejrzyj wygenerowany `C:\SMS-Awarie-Web\apache-sms.conf`, szczególnie trasowanie `/sms-api` do PHP. Testuj endpoint API i sesję: samo `Syntax OK` nie potwierdza działania. Zarejestruj pojedyncze zadanie Next.js przez `11-install-node-task.ps1`, a pojedynczy Watchdog przez `03-install-watchdog-task.ps1` po skonfigurowaniu konta usługi. Oba skrypty nie uruchamiają zadań automatycznie. Odbiornik uruchamiaj tylko przez jedną ścieżkę kontrolowaną przez Watchdog.

## 5. Testy przed udostępnieniem
1. Sprawdź `http://127.0.0.1:8090/` oraz `http://127.0.0.1:8090/sms-api?section=dashboard` (odpowiedź 401 bez sesji jest dopuszczalna; HTML Next.js zamiast odpowiedzi API jest błędem konfiguracji).
2. Utwórz pierwszego administratora przy użyciu `php/create-admin.php` według jego wymagań, bez publikowania tego skryptu przez Apache.
3. Test SMS pojazdu bez prefiksu: `PZ12345 Awaria silnika`; test grupy z prefiksem: `MAS CNC12 Brak zasilania` po utworzeniu grupy MAS.
4. Sprawdź osobne rekordy `outbox` dla zgłaszającego i numerów grupowych; w środowisku testowym używaj wyłącznie kontrolowanych numerów. Nie testuj na przypadkowych odbiorcach.
5. Zweryfikuj deduplikację SMS przychodzących, jeden PID, świeży heartbeat, restart Watchdoga oraz nieponawianie `W_TRAKCIE` i `NIEPEWNY`.
6. Uruchom `scripts/14-verify-deployment.ps1` i wykonaj próbne odtworzenie bazy na odizolowanej kopii.

## 6. LAN / HTTPS
Wystaw do sieci firmowej wyłącznie HTTPS (np. przez firmowy reverse proxy) i ogranicz firewall do podsieci użytkowników. Nie wystawiaj portu 3000, plików SQLite, config.json ani logów. Ustaw DNS i certyfikat zgodnie z polityką firmy; przetestuj cookie `Secure`, CSRF, uprawnienia i logowanie z innego komputera. Szczegóły: `docs/WDROZENIE-WINDOWS-LAN.md`.

## 7. Backup i awaria
Zarejestruj codzienną kopię: `.\scripts\16-install-backup-task.ps1 -KeepDays 30 -Target D:\SMS-Awarie-Backup` (najpierw bez `-Apply`, potem z `-Apply`). Kopie `daily-*.sqlite` starsze niż retencja są usuwane automatycznie. Trzymaj je na innym dysku niż baza.

Zapisuj regularnie spójne kopie SQLite przez skrypt backupu PHP, kopie `config.json` z kontrolą dostępu, pliki aplikacji, lockfile, konfigurację Apache i definicje zadań. W awarii najpierw zatrzymaj Watchdog i odbiornik, zabezpiecz aktualny stan, przywróć ostatnią **spójną** kopię i porównaj stan outbox. Nigdy nie ustawiaj masowo `W_TRAKCIE` ani `NIEPEWNY` na oczekujące. Patrz `docs/ODZYSKIWANIE.md`.
