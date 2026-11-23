# Instalacja SMS-Awarie 4.x

## Wymagania

- Windows 10/11, konto administratora na czas instalacji, stały adres IP serwera, wyłączone usypianie.
- XAMPP z PHP 8.2+ (w `C:\xampp`). Rozszerzenia `pdo_sqlite`, `curl`, `SimpleXML` są w XAMPP włączone domyślnie – instalator to sprawdza.
- Apache z XAMPP najlepiej jako **usługa Windows** (XAMPP Control Panel → przycisk *Svc* przy Apache), żeby panel działał po wylogowaniu i restarcie.
- Modem Huawei E5373 osiągalny z serwera (domyślnie `http://192.168.8.1`) i hasło administratora modemu.

Node.js, npm i reverse proxy z wersji 3.x **nie są już potrzebne**.

## 1. Nowa instalacja

1. Rozpakuj `sms-awarie-vX.Y.Z.zip`, np. do `C:\Instalki\SMS-Awarie-vX.Y.Z-INSTALATOR`.
2. PowerShell jako administrator, w tym katalogu:
   ```powershell
   Set-ExecutionPolicy -Scope Process Bypass
   .\scripts\install.ps1          # podgląd
   .\scripts\install.ps1 -Apply   # instalacja
   ```
   Instalator kopiuje program do `C:\SMS-Awarie`, tworzy `data`, `logs`, bazę i `data\config.json`, dopisuje do `httpd.conf` linię `Include "C:/SMS-Awarie/apache-sms-awarie.conf"` i sprawdza `httpd -t`.
3. Zrestartuj Apache (`Restart-Service Apache2.4` albo XAMPP Control Panel).
4. Otwórz `http://localhost/sms-awarie/` i utwórz pierwszego administratora.
5. W panelu **Ustawienia i modem** wpisz hasło modemu. Do tego czasu odbiornik nie wystartuje.
6. Zadania Harmonogramu (każde pyta o konto serwisowe):
   ```powershell
   .\scripts\install-watchdog-task.ps1           # odbiornik + Watchdog (co minutę)
   .\scripts\install-backup-task.ps1 -Target D:\SMS-Awarie-Backup -Apply   # kopia codziennie 02:30
   ```
7. Kontrola: `.\scripts\verify.ps1`. Na Dashboardzie stan odbiornika powinien zmienić się na RUNNING w ciągu 1–2 minut.
8. Dostęp z sieci: `http://<adres-serwera>/sms-awarie/`. Jeśli port 80 nie jest jeszcze otwarty: `.\scripts\firewall-lan.ps1 -RemoteSubnet 192.168.10.0/24`.

## 2. Aktualizacja z 3.x lub wcześniejszej 4.x

1. Wstrzymaj Watchdoga i zatrzymaj odbiornik:
   ```powershell
   New-Item C:\SMS-Awarie\data\watchdog.pause -ItemType File
   Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object CommandLine -like '*receiver.php*' | Select ProcessId
   Stop-Process -Id <PID>
   ```
2. `.\scripts\install.ps1`, a potem `.\scripts\install.ps1 -Apply`. Instalator:
   - robi kopię plików programu, `httpd.conf`, `config.json` i spójną kopię bazy do `C:\SMS-Awarie-Backups\pre-X.Y.Z-<data>`;
   - podmienia pliki programu, nie ruszając `data` i `logs`;
   - migruje schemat bazy (dotychczasowe zgłoszenia zachowują swoje numery, nowe dostają kolejne `AW/2026/00001`…);
   - przy aktualizacji z 3.x wyłącza zadanie `SMS-Awarie-v3-Web` (Next.js) i komentuje stary `Include` panelu 3.x w `httpd.conf`.
3. Zrestartuj Apache, zaloguj się na `http://localhost/sms-awarie/` dotychczasowym kontem i sprawdź Dashboard, Zgłoszenia i Ustawienia.
4. Usuń pauzę: `Remove-Item C:\SMS-Awarie\data\watchdog.pause`. Watchdog uruchomi odbiornik w ciągu minuty.
5. Po kilku dniach poprawnej pracy usuń katalog `C:\SMS-Awarie-Web` i zadanie `SMS-Awarie-v3-Web`. Stary port 8090 i reguła zapory `SMS-Awarie-v3-LAN` nie są już potrzebne.

**Wycofanie:** zatrzymaj odbiornik jak w kroku 1, skopiuj pliki z `C:\SMS-Awarie-Backups\pre-...\app` do `C:\SMS-Awarie`, przywróć `httpd.conf` z tej samej kopii, włącz zadanie `SMS-Awarie-v3-Web`. Odbiornik i panel 3.x **nie działają na bazie po migracji** (nowe kolumny), więc przy wycofaniu trzeba też przywrócić bazę `awarie.sqlite` z tej kopii. Zgłoszenia przyjęte w czasie pracy 4.0 trzeba wtedy przepisać ręcznie – dlatego testuj 4.0 od razu po aktualizacji, zanim przyjdzie dużo zgłoszeń. Szczegóły: `docs/ODZYSKIWANIE.md`.

## 3. Ustawienia w `data\config.json`

Zmieniane w panelu (Ustawienia i modem). `delete_after_import` (domyślnie `true`) usuwa odebrane SMS z modemu po zapisie w bazie, `send_per_cycle` (domyślnie 5) to liczba SMS wysyłanych w jednym cyklu odbiornika. Po zmianie ustawień modemu zrestartuj odbiornik (zatrzymaj proces – Watchdog uruchomi go ponownie).

## 4. Zapomniane hasło administratora

```powershell
C:\xampp\php\php.exe C:\SMS-Awarie\cli\reset-password.php admin
```
