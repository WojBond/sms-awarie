# SMS-Awarie – baza MS SQL Server (od 4.2.0)

Domyślnie aplikacja zapisuje dane w pliku SQLite (`data\awarie.sqlite`). Od wersji 4.2.0 może zamiast tego używać Microsoft SQL Server (2016 lub nowszy, także Express). Przełączenie robi się w panelu: **Ustawienia › Baza danych**.

## 1. Co przygotować

1. **SQL Server** osiągalny z serwera XAMPP (port TCP 1433 albo nazwana instancja, np. `localhost\SQLEXPRESS`; w SQL Server Configuration Manager włącz protokół TCP/IP).
2. **Baza i login.** Zalecany osobny login SQL z rolą `db_owner` w bazie aplikacji. Najprościej w SSMS:
   ```sql
   CREATE DATABASE SMSAwarie COLLATE Polish_100_CI_AS;
   CREATE LOGIN smsawarie WITH PASSWORD = 'silne-haslo';
   USE SMSAwarie; CREATE USER smsawarie FOR LOGIN smsawarie; ALTER ROLE db_owner ADD MEMBER smsawarie;
   ```
   Bazę może też utworzyć panel (opcja „Utwórz bazę, jeśli nie istnieje”), gdy login ma uprawnienie `dbcreator`. Tabele aplikacja zakłada sama.
   Logowanie kontem Windows (puste pole Login) działa tylko wtedy, gdy Apache i zadanie Watchdoga działają na koncie domenowym z dostępem do SQL Server.
3. **Sterownik dla PHP z XAMPP** (XAMPP go nie zawiera):
   - zainstaluj **Microsoft ODBC Driver 18 for SQL Server** (x64);
   - pobierz **Microsoft Drivers for PHP for SQL Server** w wersji zgodnej z PHP z XAMPP (`<XAMPP>\php\php.exe -v`: numer wersji, `ZTS` = Thread Safe, x64) i skopiuj plik `php_pdo_sqlsrv_<wersja>_ts_x64.dll` do `<XAMPP>\php\ext\`;
   - w `<XAMPP>\php\php.ini` dopisz `extension=pdo_sqlsrv_<wersja>_ts_x64` (nazwa pliku bez `php_` i `.dll`);
   - zrestartuj Apache i sprawdź: `<XAMPP>\php\php.exe -m` musi pokazać `pdo_sqlsrv`.

## 2. Przełączenie

1. Panel › **Ustawienia › Baza danych** › *Połączenie z MS SQL Server*: serwer, baza, login, hasło › **Zapisz i sprawdź połączenie**.
2. Po udanym teście pojawi się wybór:
   - **Przenieś obecne dane** – kopiuje wszystko z SQLite (zgłoszenia, SMS, kolejkę, pojazdy, grupy, konta, dziennik) i porównuje liczbę wierszy w każdej tabeli;
   - **Zacznij od pustej bazy** – bez zgłoszeń, SMS, pojazdów i grup; przenoszone są tylko konta użytkowników, żeby można było się zalogować;
   - **Użyj danych z bazy MS SQL** – pokazuje się, gdy wskazana baza ma już dane SMS-Awarie (np. po wcześniejszym przełączeniu).
3. **Przełącz na MS SQL.** Odbiornik zostaje na kilka sekund wstrzymany (`data\maintenance.flag`), a potem sam łączy się z nową bazą – bez restartu. Plik SQLite zostaje bez zmian.

Przeniesienie danych wymaga pustej bazy docelowej: aplikacja nie dopisuje danych do bazy, w której są już zgłoszenia lub konta.

## 3. Kopie zapasowe

Zadanie `SMS-Awarie-v3-Backup` (i przycisk *Utwórz kopię teraz*) przy MS SQL wykonuje `BACKUP DATABASE ... WITH COPY_ONLY, CHECKSUM` i sprawdza plik poleceniem `RESTORE VERIFYONLY`. `COPY_ONLY` nie zaburza kopii robionych przez administratora SQL.

- Plik `.bak` powstaje **na serwerze SQL** – w katalogu z pola *Katalog kopii na serwerze SQL* albo w domyślnym katalogu kopii instancji. Konto usługi SQL Server musi mieć prawo zapisu do tego katalogu.
- Parametr `-Target` w `install-backup-task.ps1` jest wtedy ścieżką na serwerze SQL.
- Stare kopie (`daily-*.bak`) są usuwane po `-KeepDays` dniach tylko wtedy, gdy katalog jest widoczny z komputera z XAMPP (ten sam serwer albo udział sieciowy). W przeciwnym razie retencję ustaw po stronie SQL Server.

## 4. Awaria serwera SQL

- Panel pokazuje stronę „Brak połączenia z bazą danych”.
- Odbiornik ponawia połączenie co minutę. Nie odczytuje wtedy SMS-ów, więc czekają one w modemie (pamięć modemu: ok. 100 wiadomości).
- Powrót do SQLite bez panelu:
  ```powershell
  <XAMPP>\php\php.exe <XAMPP>\htdocs\sms-awarie\cli\db.php sqlite
  ```
  W pliku SQLite jest stan z chwili przełączenia na MS SQL. Dane zapisane później zostają w MS SQL.
- Stan bazy: `<XAMPP>\php\php.exe <XAMPP>\htdocs\sms-awarie\cli\db.php status`.

## 5. Gdzie są ustawienia

`data\config.json`, klucz `db` (chroniony `.htaccess` tak jak hasło modemu):

```json
"db": {"driver": "sqlsrv", "server": "localhost\\SQLEXPRESS", "database": "SMSAwarie", "user": "smsawarie", "pass": "…", "encrypt": true, "trust_cert": true, "backup_dir": ""}
```

`"driver": "sqlite"` = plik SQLite. Ustawienia MS SQL zostają zapamiętane po powrocie do SQLite.
