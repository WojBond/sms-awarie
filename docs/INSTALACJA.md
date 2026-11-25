# Instalacja SMS-Awarie 4.2

Aplikacja działa w standardowym folderze stron XAMPP: **`<XAMPP>\htdocs\sms-awarie`**, a panel jest pod adresem **`http://serwer/sms-awarie/`**. `<XAMPP>` to folder, w którym zainstalowano XAMPP, na dowolnym dysku (np. `C:\xampp`, `D:\xampp`, `E:\Serwer\xampp`). Nie trzeba zmieniać `httpd.conf`.

## Wymagania

- Windows 10/11, konto administratora na czas instalacji, stały adres IP serwera, wyłączone usypianie.
- XAMPP z PHP 8.2+. Rozszerzenia `pdo_sqlite`, `curl` i `SimpleXML` są w XAMPP włączone domyślnie, a instalator to sprawdza.
- **`AllowOverride All` dla htdocs**, czyli ustawienie domyślne XAMPP. Pliki `.htaccess` blokują wtedy dostęp z przeglądarki do bazy, `config.json` (hasło modemu) i logów. Instalator, `verify.ps1` i Dashboard ostrzegają, gdy ochrona nie działa.
- Apache najlepiej uruchomić jako **usługę Windows** (XAMPP Control Panel → przycisk *Svc* przy Apache), żeby panel działał po wylogowaniu i restarcie.
- Opcjonalnie Microsoft SQL Server zamiast pliku SQLite – zobacz `docs/MSSQL.md` (można przełączyć w dowolnym momencie po instalacji).
- Modem Huawei E5373 osiągalny z serwera (domyślnie `http://192.168.8.1`) oraz hasło administratora modemu.

## Gdzie jest XAMPP

Skrypty (`install.ps1`, `install-watchdog-task.ps1`, `install-backup-task.ps1`, `verify.ps1`) same szukają XAMPP po kolei: wpis w rejestrze z instalatora XAMPP, usługa Apache, folder `xampp` w katalogu głównym każdego dysku (`C:\xampp`, `D:\xampp`, …). Na początku wypisują, który folder znalazły. Jeśli XAMPP leży gdzie indziej albo jest ich kilka, podaj go wprost parametrem `-Xampp`, np. `-Xampp E:\Serwer\xampp` – tym samym w każdym skrypcie.

## 1. Instalacja (świeża)

1. Rozpakuj `sms-awarie-vX.Y.Z.zip`, np. do `D:\Instalki\`.
2. Otwórz PowerShell jako administrator i przejdź do rozpakowanego katalogu:
   ```powershell
   Set-ExecutionPolicy -Scope Process Bypass
   .\scripts\install.ps1                      # podgląd: pokazuje wykryty XAMPP i co zrobi
   .\scripts\install.ps1 -Apply               # instalacja do <XAMPP>\htdocs\sms-awarie
   .\scripts\install.ps1 -Xampp D:\xampp -Apply   # gdy XAMPP nie został wykryty
   ```
   Instalator kopiuje aplikację i zakłada pustą bazę. Niczego nie przenosi ze starszych instalacji.

   **Instalacja ręczna:** zamiast instalatora możesz skopiować katalog `htdocs\sms-awarie` z paczki do `<XAMPP>\htdocs\` i uruchomić `<XAMPP>\php\php.exe <XAMPP>\htdocs\sms-awarie\cli\init.php`.
3. Otwórz `http://localhost/sms-awarie/` i utwórz pierwszego administratora.
4. W menu **Ustawienia › Modem** wpisz hasło modemu. Dopóki go nie ustawisz, odbiornik nie wystartuje.
5. Zarejestruj zadania Harmonogramu. Każde z nich pyta o konto serwisowe (dodaj `-Xampp <ścieżka>`, jeśli podawałeś ją instalatorowi):
   ```powershell
   .\scripts\install-watchdog-task.ps1                                       # odbiornik + Watchdog (co minutę)
   .\scripts\install-backup-task.ps1 -Target E:\SMS-Awarie-Backup -Apply    # kopia codziennie o 02:30
   ```
   Kopie najlepiej trzymać na innym dysku niż XAMPP albo na udziale sieciowym.
6. Sprawdź instalację: `.\scripts\verify.ps1`. Skrypt sprawdza też, czy `data\config.json` jest zablokowany z sieci. Na Dashboardzie stan odbiornika powinien zmienić się na RUNNING w ciągu 1–2 minut.
7. Testowy SMS: **Ustawienia › Autoodpowiedzi › Testowy SMS** – wpisz swój numer i sprawdź, czy wiadomość dochodzi.
8. Dostęp z sieci: `http://<adres-serwera>/sms-awarie/`. Port 80 jest zwykle już otwarty dla innych aplikacji XAMPP. Jeśli nie jest, uruchom `.\scripts\firewall-lan.ps1 -RemoteSubnet 192.168.10.0/24`.

## 2. Aktualizacja (4.1 → kolejne wersje)

1. W panelu otwórz **Ustawienia › Usługi** i kliknij **Zatrzymaj wszystko** (od wersji 4.4). Odbiornik kończy bieżący krok i się zatrzymuje, a Watchdog i kopia zapasowa są wstrzymane – poczekaj na komunikat „Wszystkie usługi zatrzymane”.
   Aktualizując z wersji starszej niż 4.4, zrób to ręcznie:
   ```powershell
   New-Item <XAMPP>\htdocs\sms-awarie\data\watchdog.pause -ItemType File
   Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object CommandLine -like '*receiver.php*' | Select ProcessId
   Stop-Process -Id <PID>
   ```
2. Uruchom `.\scripts\install.ps1 -Apply` (z `-Xampp`, jeśli trzeba). Instalator robi kopię plików i bazy do `<dysk XAMPP>:\SMS-Awarie-Backups\pre-<wersja>-<data>` (inne miejsce: `-BackupRoot`), podmienia program bez ruszania `data` i `logs`, a następnie migruje bazę.
3. W panelu kliknij **Ustawienia › Usługi › Wznów wszystko** (przy aktualizacji ze starszej wersji: usuń `data\watchdog.pause`). Watchdog uruchomi odbiornik w ciągu kilku minut.

Jeśli XAMPP przeniesiesz na inny dysk, zarejestruj zadania ponownie z `-Replace`.

**Wycofanie:** zatrzymaj odbiornik i skopiuj pliki z `<dysk XAMPP>:\SMS-Awarie-Backups\pre-...\app` z powrotem. Szczegóły są w `docs/ODZYSKIWANIE.md`.

## 3. Ustawienia w `data\config.json`

Ustawienia zmieniasz w panelu (Ustawienia › Modem):
- `delete_after_import` (domyślnie `true`) usuwa odebrane SMS z modemu po zapisie w bazie;
- `send_per_cycle` (domyślnie 5) określa, ile SMS odbiornik wysyła w jednym cyklu.

Po zmianie ustawień modemu zrestartuj odbiornik: **Ustawienia › Usługi › Odbiornik SMS** – Zatrzymaj, a potem Uruchom.

## 3a. Usługi (Ustawienia › Usługi)

- **Odbiornik SMS – Zatrzymaj/Uruchom:** plik `data\receiver.stop`. Odbiornik kończy pracę między krokami (nie w trakcie zapisu SMS ani wysyłki), a Watchdog go nie uruchamia, dopóki plik istnieje.
- **Watchdog – Wstrzymaj/Wznów:** plik `data\watchdog.pause`. Watchdog nic nie robi; działający odbiornik pracuje dalej, ale nikt go nie restartuje.
- **Kopia zapasowa – Wstrzymaj/Wznów:** plik `data\backup.pause`. Zadanie backupu kończy się bez kopii (wpis „BACKUP POMINIETY”).
- **Wyłącz/Włącz w Harmonogramie, Uruchom teraz:** zmienia same zadania `SMS-Awarie-*` w Harmonogramie zadań Windows. Wymaga, żeby Apache działał z uprawnieniami administratora (np. jako usługa na koncie Local System). Bez tego panel pokazuje polecenie, np. `Disable-ScheduledTask -TaskName 'SMS-Awarie-v3-Watchdog'`, do uruchomienia w PowerShell jako administrator.

Flagi przetrwają aktualizację i restart serwera. Dashboard przypomina, gdy któraś usługa jest zatrzymana.

## 3b. Uprawnienia użytkowników do grup zgłoszeń (od 4.5.0)

Użytkownicy › konto › **Dostęp do grup zgłoszeń** (Operator i Tylko odczyt; Administrator ma zawsze pełny dostęp):

- **Wszystkie grupy, także nowe** – jeden poziom dla każdej grupy (tak działają konta sprzed 4.5.0).
- **Tylko wybrane grupy** – poziom osobno dla każdej grupy; nowa grupa nie jest widoczna, dopóki administrator jej nie przydzieli (także na stronie grupy: Ustawienia grupy › Uprawnienia użytkowników).

| Poziom | Co może |
|---|---|
| Podgląd | zgłoszenia, statystyki i SMS-y grupy, eksport CSV |
| Obsługa zgłoszeń | + zmiana statusu i opisu zgłoszeń |
| Obsługa i obiekty | + dodawanie i wyłączanie obiektów grupy (np. pojazdów) |

Rola Tylko odczyt ma najwyżej Podgląd. Dodatkowo: **Wysyłka i anulowanie SMS** (Nowy SMS, Anuluj w SMS wychodzących) i **SMS-y nieprzypisane do grup** (niepoprawne SMS-y, wysyłki ręczne).

## 4. Zapomniane hasło administratora

```powershell
<XAMPP>\php\php.exe <XAMPP>\htdocs\sms-awarie\cli\reset-password.php admin
```
