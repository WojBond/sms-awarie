# SMS-Awarie v3.0 — instalacja na Windows 10/11 w sieci firmowej

## Wymagania
Windows 10/11 x64 z aktualizacjami bezpieczeństwa (Windows 10 tylko przy zapewnionym wsparciu/ESU), konto administracyjne na czas instalacji, XAMPP z PHP 8.2 i rozszerzeniami `pdo_sqlite`, `curl`, `SimpleXML`, `pdo_sqlsrv` jeśli używany SQL Server; Node.js i npm zgodne z Next.js 15, modem Huawei E5373 osiągalny po HTTP, stały adres IP/DNS komputera, reguły firewall i kopie zapasowe. Urządzenie musi pozostać uruchomione (bez uśpienia). Node.js i XAMPP NIE są dołączone.

**Bezpieczeństwo:** HTTP na porcie 8090 jest przeznaczony wyłącznie do zaufanego segmentu lub testów. Panel obsługuje hasła i sesje — przed produkcyjnym udostępnieniem w LAN skonfiguruj HTTPS (certyfikat firmowy, np. terminacja TLS na istniejącym reverse proxy) i wymuś HTTPS; ustaw poprawne `X-Forwarded-Proto`, sprawdź `Secure` cookies i CSRF. Nie wystawiaj portów 3000/8090 do Internetu. Nie publikuj katalogów `data`/`logs`.

## 1. Przygotowanie — bez zatrzymywania produkcji
1. Rozpakuj ZIP do `C:\SMS-Awarie-Release-v3`. Przejrzyj `README.md`, `docs/INSTALACJA.md`, `docs/ODZYSKIWANIE.md`.
2. Uruchom `scripts/01-preflight.ps1` oraz `scripts/10-install-files.ps1` bez `-Apply`.
3. Zrób spójną kopię SQLite z `scripts/02-backup-sqlite.php` zgodnie z `docs/INSTALACJA.md`; zapisz osobno `config.json` (hasła!) oraz bieżące pliki, build i ustawienia Apache. Zweryfikuj kopię i liczbę rekordów. Przechowuj kopie poza komputerem.
4. Sprawdź, czy komputer ma wymagane moduły PHP, dostęp do modemu i uprawnienia kont serwisowych. Zarezerwuj IP lub skonfiguruj DNS. Nie uruchamiaj dwóch odbiorników.

## 2. Okno serwisowe
1. Wyłącz stare zadanie Watchdoga i wszystkie autostarty odbiornika. Utwórz `C:\SMS-Awarie\data\watchdog.pause`. Zaczekaj na zakończenie operacji, sprawdź PID i bezpiecznie zatrzymaj `receiver.php` (bez usuwania `receiver.lock`). Zatrzymaj poprzedni proces Node.
2. Wykonaj dodatkową spójną kopię SQLite i zachowaj pliki WAL/SHM wyłącznie przy zatrzymanych procesach. Zachowaj snapshot do odtworzenia.
3. Uruchom `scripts/10-install-files.ps1 -Apply`. Skrypt nie kopiuje danych i konfiguracji modemu. Uwaga: skrypt kopiuje tylko pliki aplikacji; nie przenosi danych z innego komputera.
4. Przenieś bezpiecznie plik `package-lock.json` z wcześniejszej instalacji do `C:\SMS-Awarie-Web\web\`. W tym pakiecie nie ma lockfile. Uruchom `cd C:\SMS-Awarie-Web\web; npm.cmd ci; npm.cmd run typecheck; npm.cmd run build` (na maszynie testowej w pierwszej kolejności).
5. Uruchom `scripts/12-configure-apache.ps1 -BindIP 127.0.0.1 -Port 8090 -Apply` do testów lokalnych. Skrypt nie uruchamia/restartuje Apache. Włącz `proxy_module` i `proxy_http_module` w httpd.conf przed wywołaniem. Skontroluj `httpd.exe -t`.
6. Zainstaluj zadanie frontendu `scripts/11-install-node-task.ps1` i zadanie Watchdoga `scripts/03-install-watchdog-task.ps1` (każde tylko raz; nie nadpisują istniejących zadań). Skonfiguruj konto serwisowe z prawami do katalogów, bez lokalnego administratora, jeśli to możliwe. Sprawdź istniejące zadania przed rejestracją.
7. Uruchom Apache, następnie zadanie Node. Sprawdź `http://127.0.0.1:8090` i `http://127.0.0.1:8090/sms-api?section=dashboard` (API może zwrócić 401 bez logowania). Utwórz pierwszego administratora z `php/create-admin.php` wyłącznie jeśli nie istnieje.
8. Usuń `watchdog.pause`, uruchom jedyny Watchdog. Sprawdź PID, heartbeat, logi, brak podwójnego przetwarzania, kolejkę `W_TRAKCIE`/`NIEPEWNY` i Dashboard. Nie włączaj ACK bez osobnej zgody.

## 3. Sieć firmowa
1. Zapewnij statyczny adres LAN, np. `192.168.10.20`, i firmowy DNS (przykład, nie używaj bez dostosowania).
2. W pierwszej kolejności skonfiguruj certyfikat i terminację HTTPS na reverse proxy. Najbezpieczniej pozostawić Apache aplikacyjny na `127.0.0.1:8090`, a użytkownikom udostępnić tylko HTTPS/443 na reverse proxy. Jeśli świadomie używasz bezpośredniego HTTP wyłącznie w izolowanym segmencie testowym, wykonaj `scripts/12-configure-apache.ps1 -BindIP <IP_SERWERA> -Port 8090 -Apply`, restart Apache i `scripts/13-firewall-lan.ps1 -RemoteSubnet <SIEC_CIDR>`.
3. Z komputerów firmowych używaj `https://sms-awarie.twoja-firma.local/` po konfiguracji HTTPS. Przetestuj role i logowanie, nagłówki proxy, ciasteczka sesji, przekierowania i brak dostępu do `data`.

## 4. Testy akceptacyjne
- `scripts/14-verify-deployment.ps1`; `npm.cmd run typecheck`; `npm.cmd run build`; `httpd.exe -t`.
- Jeden PID odbiornika, świeży heartbeat i widoczne zdarzenia Watchdoga.
- Jeden przychodzący SMS → jeden rekord `sms` i jeden `tickets`; ponowne odczytanie tego SMS nie tworzy duplikatu.
- Rekordy `outbox` ze statusami `W_TRAKCIE` i `NIEPEWNY` nie są automatycznie ponawiane.
- Test logowania i ról z dwóch komputerów w LAN, API nie jest publiczne.
- Backup i test odzyskiwania na odizolowanej kopii.

## 5. Odzyskiwanie
Zobacz `docs/ODZYSKIWANIE.md`. Przy błędzie samego kodu przywracaj pliki z katalogu `C:\SMS-Awarie-Backups\<timestamp>`, **nie nadpisuj bazy SQLite starszą kopią**, jeśli od tego czasu odebrano nowe SMS. Zatrzymaj zadania, zachowaj świeży snapshot i logi, przywróć poprzednie pliki, uruchom pojedynczy odbiornik i sprawdź integralność. Przy awarii dysku odzyskaj najnowszy spójny backup SQLite i `config.json`, sprawdź dane oraz ręcznie przeanalizuj SMS i statusy wysyłki. Nie resetuj automatycznie statusów wysyłki.
