# Instalacja SMS-Awarie 4.1

Aplikacja działa w standardowym folderze stron XAMPP: **`C:\xampp\htdocs\sms-awarie`**, a panel jest pod adresem **`http://serwer/sms-awarie/`**. Nie trzeba zmieniać `httpd.conf`.

## Wymagania

- Windows 10/11, konto administratora na czas instalacji, stały adres IP serwera, wyłączone usypianie.
- XAMPP z PHP 8.2+ w `C:\xampp`. Rozszerzenia `pdo_sqlite`, `curl` i `SimpleXML` są w XAMPP włączone domyślnie, a instalator to sprawdza.
- **`AllowOverride All` dla htdocs**, czyli ustawienie domyślne XAMPP. Pliki `.htaccess` blokują wtedy dostęp z przeglądarki do bazy, `config.json` (hasło modemu) i logów. Instalator, `verify.ps1` i Dashboard ostrzegają, gdy ochrona nie działa.
- Apache najlepiej uruchomić jako **usługę Windows** (XAMPP Control Panel → przycisk *Svc* przy Apache), żeby panel działał po wylogowaniu i restarcie.
- Modem Huawei E5373 osiągalny z serwera (domyślnie `http://192.168.8.1`) oraz hasło administratora modemu.

## 1. Nowa instalacja

1. Rozpakuj `sms-awarie-vX.Y.Z.zip`, np. do `C:\Instalki\`.
2. Otwórz PowerShell jako administrator i przejdź do rozpakowanego katalogu:
   ```powershell
   Set-ExecutionPolicy -Scope Process Bypass
   .\scripts\install.ps1          # podgląd
   .\scripts\install.ps1 -Apply   # instalacja do C:\xampp\htdocs\sms-awarie
   ```
   **Instalacja ręczna:** zamiast instalatora możesz skopiować katalog `htdocs\sms-awarie` z paczki do `C:\xampp\htdocs\` i uruchomić `C:\xampp\php\php.exe C:\xampp\htdocs\sms-awarie\cli\init.php`.
3. Otwórz `http://localhost/sms-awarie/` i utwórz pierwszego administratora.
4. W zakładce **Ustawienia i modem** wpisz hasło modemu. Dopóki go nie ustawisz, odbiornik nie wystartuje.
5. Zarejestruj zadania Harmonogramu. Każde z nich pyta o konto serwisowe:
   ```powershell
   .\scripts\install-watchdog-task.ps1                                       # odbiornik + Watchdog (co minutę)
   .\scripts\install-backup-task.ps1 -Target D:\SMS-Awarie-Backup -Apply    # kopia codziennie o 02:30
   ```
6. Sprawdź instalację: `.\scripts\verify.ps1`. Skrypt sprawdza też, czy `data\config.json` jest zablokowany z sieci. Na Dashboardzie stan odbiornika powinien zmienić się na RUNNING w ciągu 1–2 minut.
7. Dostęp z sieci: `http://<adres-serwera>/sms-awarie/`. Port 80 jest zwykle już otwarty dla innych aplikacji XAMPP. Jeśli nie jest, uruchom `.\scripts\firewall-lan.ps1 -RemoteSubnet 192.168.10.0/24`.

## 2. Przeniesienie z `C:\SMS-Awarie` (wersje 3.x i 4.0.0)

1. Wstrzymaj Watchdoga i zatrzymaj odbiornik:
   ```powershell
   New-Item C:\SMS-Awarie\data\watchdog.pause -ItemType File
   Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object CommandLine -like '*receiver.php*' | Select ProcessId
   Stop-Process -Id <PID>
   ```
2. Uruchom `.\scripts\install.ps1`, a potem `.\scripts\install.ps1 -Apply`. Instalator wykona następujące kroki:
   - robi kopię zapasową bazy, `config.json` i `httpd.conf` do `C:\SMS-Awarie-Backups\pre-4.1.0-<data>`;
   - kopiuje bazę (spójną kopią), `config.json` i `data\backups` do `C:\xampp\htdocs\sms-awarie\data`; katalog `C:\SMS-Awarie` zostaje nienaruszony;
   - migruje schemat bazy (dotychczasowe zgłoszenia zachowują swoje numery);
   - komentuje stare wpisy `Include` w `httpd.conf` (panel 3.x i `Alias` z 4.0.0) i wyłącza zadania wskazujące starą lokalizację (`SMS-Awarie-v3-Web`, `-Watchdog`, `-Backup`).
3. Jeśli instalator zmienił `httpd.conf`, zrestartuj Apache (`Restart-Service Apache2.4` albo XAMPP Control Panel).
4. Zaloguj się na `http://localhost/sms-awarie/` dotychczasowym kontem i sprawdź Dashboard, Zgłoszenia i Ustawienia.
5. Zarejestruj zadania od nowa dla nowej lokalizacji:
   ```powershell
   .\scripts\install-watchdog-task.ps1 -Replace
   .\scripts\install-backup-task.ps1 -Target D:\SMS-Awarie-Backup -Replace -Apply
   ```
6. Po kilku dniach poprawnej pracy usuń `C:\SMS-Awarie`, `C:\SMS-Awarie-Web` (jeśli był) i wyłączone zadanie `SMS-Awarie-v3-Web`.

## 3. Aktualizacja w htdocs (4.1 → kolejne wersje)

Wstrzymaj Watchdoga i zatrzymaj odbiornik jak w punkcie 2.1, ale w ścieżce `C:\xampp\htdocs\sms-awarie\data\watchdog.pause`. Potem uruchom `.\scripts\install.ps1 -Apply`. Instalator robi kopię plików i bazy, podmienia program bez ruszania `data` i `logs`, a następnie migruje bazę. Na koniec usuń `watchdog.pause`.

**Wycofanie:** zatrzymaj odbiornik i skopiuj pliki z `C:\SMS-Awarie-Backups\pre-...\app` z powrotem. Przy powrocie do wersji 3.x odbiornik i panel 3.x nie działają na bazie po migracji, więc trzeba też przywrócić `awarie.sqlite` z kopii, a zgłoszenia przyjęte w międzyczasie przepisać ręcznie. Szczegóły są w `docs/ODZYSKIWANIE.md`.

## 4. Ustawienia w `data\config.json`

Ustawienia zmieniasz w panelu (Ustawienia i modem):
- `delete_after_import` (domyślnie `true`) usuwa odebrane SMS z modemu po zapisie w bazie;
- `send_per_cycle` (domyślnie 5) określa, ile SMS odbiornik wysyła w jednym cyklu.

Po zmianie ustawień modemu zrestartuj odbiornik: zatrzymaj proces, a Watchdog uruchomi go ponownie.

## 5. Zapomniane hasło administratora

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\sms-awarie\cli\reset-password.php admin
```
