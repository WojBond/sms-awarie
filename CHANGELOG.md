# Changelog SMS-Awarie

## 4.8.0 – 2026-11-27 (minor)
- **Dwa tryby menu bocznego na PC**, wybierane przełącznikiem **Chowaj automatycznie** pod nazwą w menu bocznym:
  1. **Automatyczny** (przełącznik włączony): menu jest wąskim paskiem ikon i rozwija się po najechaniu kursorem (po ok. 0,1 s), a chowa po zjechaniu (po ok. 0,35 s, żeby nie migało). Rozwinięte menu wysuwa się nad treścią, więc główne okno cały czas zostaje szerokie. Działa też z klawiatury (Tab) i na ekranach dotykowych (dotknięcie Grup zgłoszeń / Ustawień rozwija menu).
  2. **Stały** (przełącznik wyłączony): menu cały czas zwinięte albo cały czas rozwinięte, przełączane przyciskiem obok nazwy *SMS-Awarie*, jak w 4.7.0.
- Wybór trybu (i stan menu w trybie stałym) jest zapamiętany w przeglądarce i ustawiany przed narysowaniem strony. Gdy otwarta jest lista zmian z plakietki wersji, menu się nie chowa.
- Telefon / Zebra bez zmian (menu wysuwane przyciskiem *Menu*). Bez zmian w bazie i odbiorniku. Aktualizacja: skopiuj pliki (`scripts\install.ps1 -Apply`).

## 4.7.0 – 2026-11-27 (minor)
- **Chowane menu boczne (PC, ekran od 1000 px):** przycisk obok nazwy *SMS-Awarie* zwija menu do wąskiego paska ikon (72 px) i z powrotem. Główne okno automatycznie zajmuje zwolnione miejsce (margines i maksymalna szerokość treści liczone od szerokości menu, płynne przejście). Stan jest zapamiętany w przeglądarce (`localStorage`) i ustawiany przed narysowaniem strony, więc menu nie „mruga” przy przechodzeniu między stronami.
- W pasku ikon: podpowiedź z nazwą pozycji po najechaniu, wyróżniona aktywna strona (także gdy jest w podmenu), kliknięcie *Grupy zgłoszeń* / *Ustawienia* rozwija menu razem z podmenu. W stopce zostają awatar, przycisk wylogowania (ikona) i plakietka wersji.
- **Telefon / Zebra:** menu jak dotąd wysuwane przyciskiem *Menu*; doszedł przycisk zamknięcia (X) w nagłówku menu i zamykanie klawiszem Esc.
- Lista zmian (plakietka wersji w stopce menu) otwiera się obok menu i nie jest już przycinana do jego szerokości.
- Bez zmian w bazie i odbiorniku. Aktualizacja: skopiuj pliki (`scripts\install.ps1 -Apply`).

## 4.6.1 – 2026-11-26 (patch)
- **Poprawka: stan Watchdoga „Nieznany”.** `watchdog.ps1` zapisywał `data\watchdog-state.json` przez `Set-Content -Encoding UTF8`, a Windows PowerShell 5.1 dodaje wtedy na początku pliku BOM UTF-8. `json_decode()` w PHP zwracał `null`, więc Ustawienia › Usługi pokazywały *Nieznany*, a Dashboard *Brak danych Watchdoga*, choć Watchdog działał. To samo dotyczyło pierwszego wiersza `logs\watchdog-events.jsonl` (Logi).
- Watchdog zapisuje stan, `watchdog-events.jsonl` i `watchdog.log` w UTF-8 bez BOM (`[IO.File]::WriteAllText/AppendAllText`). Panel usuwa BOM przy odczycie (`read_json()` / `strip_bom()` w `web/lib.php`), więc działa od razu, także ze starym plikiem stanu i z `data\config.json` zapisanym w Notatniku.
- Nowe stany na Usługach i Dashboardzie: **Brak danych – nie uruchomił się** (brak pliku stanu) i **Nie działa – brak kontroli** (ostatnia kontrola starsza niż 5 min), z podpowiedzią: zadanie niezarejestrowane, wyłączone w Harmonogramie albo kończące się błędem.
- Aktualizacja: skopiuj pliki (`scripts\install.ps1 -Apply`). Zadania Harmonogramu nie trzeba rejestrować ponownie.

## 4.6.0 – 2026-11-25 (minor)
- **Ustawienia › Poczta e-mail** (nowe podmenu): konto SMTP do wysyłania powiadomień – serwer, port, szyfrowanie (STARTTLS / SSL/TLS / bez), login, hasło (puste pole = bez zmian), adres i nazwa nadawcy, weryfikacja certyfikatu, opcjonalny adres panelu (link w e-mailu). **Testowy e-mail** wysyłany od razu z panelu z przebiegiem rozmowy z serwerem (bez hasła). Lista ostatnich 20 e-maili, *Ponów e-maile z błędem*, *Anuluj oczekujące*. Klient SMTP w `mail.php`, bez bibliotek (wymaga `extension=openssl`, w XAMPP włączone).
- **Użytkownicy:** pola **E-mail** i **Telefon** (normalizacja spacji i myślników, walidacja). Opcja **Może logować się do panelu** – odznaczona = konto tylko do powiadomień (bez hasła, nie da się na nie zalogować; plakietka *Tylko powiadomienia*). W koncie widać, z których grup dostaje powiadomienia. Zawsze zostaje co najmniej jeden administrator z logowaniem.
- **Grupy zgłoszeń › Odbiorcy powiadomień:** tabela aktywnych kont z telefonem, e-mailem, dostępem w panelu i polami **SMS** / **E-mail** (wyłączone, gdy konto nie ma telefonu lub adresu). Konto bez dostępu do grupy w panelu jest oznaczone *brak dostępu* – dostaje powiadomienia, ale nie zobaczy zgłoszeń (uprawnienia z 4.5.0 bez zmian). Pole numerów zniknęło z Ustawień grupy.
- **Migracja bez utraty danych:** dotychczasowe numery z grup zostają jako *Dodatkowe numery spoza kont* i dalej dostają SMS. Przy każdym numerze **Zamień na konto** otwiera nowe konto z tym telefonem (domyślnie bez logowania) i po zapisie dodaje je do odbiorców grupy, a numer znika z listy. Numer należący już do odbiorcy z SMS jest usuwany z listy automatycznie; przy wysyłce numery są porównywane po ostatnich 9 cyfrach, więc nikt nie dostaje dwóch SMS-ów.
- Odbiornik: e-mail o nowym zgłoszeniu (temat z numerem, grupą i obiektem; treść z polskimi znakami, opisem, zgłaszającym i statusem) trafia do kolejki `mail_outbox` w tej samej transakcji co SMS-y i jest wysyłany po SMS-ach w każdym cyklu. Nieudana wysyłka: ponowienie po 1, 5, 15 i 60 min, potem *Błąd*. Przy wyłączonej poczcie e-maile nie są kolejkowane.
- Schemat bazy v5 (SQLite i MS SQL, migracja automatyczna): `web_users.email`, `phone`, `login_enabled`, tabele `group_members` i `mail_outbox`; kopiowanie między bazami obejmuje nowe dane.

## 4.5.1 – 2026-11-25 (patch)
- Poprawiony wygląd sekcji **Dostęp do grup zgłoszeń** (Użytkownicy › konto): przyciski wyboru miały szerokość 100% (globalny styl pól), przez co były ogromne i odklejone od etykiet. Zakres to teraz dwa kafelki *Wszystkie grupy* / *Tylko wybrane grupy* z opisem i wyróżnieniem wybranego.
- Pokazywana jest tylko część pasująca do wyboru: poziom dla wszystkich grup albo tabela grup (CSS `:has`, bez JavaScriptu). *Uprawnienia dodatkowe* w osobnej części pod kreską.
- Tabela grup zostaje tabelą na telefonie; listy wyboru poziomu w tabelach uprawnień (także na stronie grupy) mają rozsądną szerokość na PC.

## 4.5.0 – 2026-11-25 (minor)
- **Uprawnienia per grupa zgłoszeń** dla ról Operator i Tylko odczyt. W Użytkownicy każde konto ma zakres: *Wszystkie grupy, także nowe* z jednym poziomem albo *Tylko wybrane grupy* z poziomem osobno dla każdej grupy. To samo dla jednej grupy na jej stronie: Ustawienia grupy › **Uprawnienia użytkowników**.
- Poziomy: **Podgląd** (zgłoszenia, statystyki, SMS-y grupy), **Obsługa zgłoszeń** (zmiana statusu i opisu), **Obsługa i obiekty** (dodatkowo dodawanie i wyłączanie obiektów grupy, np. pojazdów). Rola Tylko odczyt ma zawsze najwyżej Podgląd; Administrator ma pełny dostęp.
- Uprawnienia dodatkowe konta: **Wysyłka i anulowanie SMS** oraz **SMS-y nieprzypisane do grup** (niepoprawne SMS-y, wysyłki ręczne i testowe).
- Filtrowanie po stronie serwera: menu Grupy zgłoszeń, Dashboard (liczniki, otwarte zgłoszenia, wysyłki do weryfikacji), Zgłoszenia i eksport CSV, lista i strona grupy, SMS odebrane (po zgłoszeniu z SMS-a), SMS wychodzące (po powiadomieniu zgłoszenia). Zapis zgłoszenia, pojazdu i wysyłka są sprawdzane także przy bezpośrednim wywołaniu.
- Schemat bazy v4 (SQLite i MS SQL, migracja automatyczna): kolumny `web_users.groups_all`, `can_send`, `see_unassigned` i tabela `user_group_access`; kopiowanie między bazami obejmuje uprawnienia. Istniejące konta dostają *Wszystkie grupy* z dotychczasowym zakresem, więc po aktualizacji nic się nie zmienia, dopóki administrator nie zawęzi dostępu.

## 4.4.0 – 2026-11-25 (minor)
- **Ustawienia › Usługi** (nowe podmenu): stan odbiornika, Watchdoga i kopii zapasowej z przyciskami Zatrzymaj/Uruchom (odbiornik) i Wstrzymaj/Wznów (Watchdog, backup). Działa bez uprawnień administratora – przez pliki `data\receiver.stop`, `data\watchdog.pause`, `data\backup.pause`.
- **Zatrzymaj wszystko / Wznów wszystko** – jedno kliknięcie przed i po aktualizacji. Panel czeka do 25 s, aż odbiornik potwierdzi zatrzymanie.
- Odbiornik sprawdza `receiver.stop` co sekundę w przerwach i kończy pracę tylko między krokami (heartbeat `STOPPED`); nie startuje, gdy plik istnieje. Watchdog nie uruchamia zatrzymanego odbiornika (stan `RECEIVER_STOPPED`), a `backup.php` pomija kopię przy `backup.pause`.
- Zadania `SMS-Awarie-*` z Harmonogramu zadań Windows: stan, ostatnie i następne uruchomienie, konto, **Wyłącz/Włącz w Harmonogramie** i **Uruchom teraz** (PowerShell; bez uprawnień administratora panel podaje polecenie do uruchomienia ręcznie). Po wznowieniu panel próbuje od razu uruchomić Watchdoga.
- Dashboard ostrzega, gdy któraś usługa jest zatrzymana; czytelne nazwy stanów Watchdoga (Wstrzymany, Uruchamia się, Brak odpowiedzi…).

## 4.3.1 – 2026-11-25 (patch)
- **Naprawiony błąd krytyczny odbiornika** `db(): Argument #1 ($s) must be of type ?array, string given` (receiver.php:118). Występował, gdy po aktualizacji do 4.2+ został stary `receiver.php` z 4.0–4.1 (`db('sqlite', $cfg)`), a odbiornik był uruchamiany ponownie, np. przez Watchdog po restarcie routera. `db()` przyjmuje teraz także dawne wywołanie; ustawienia bazy i tak pochodzą z `config.json`.
- **Ponowne połączenie z modemem Huawei po restarcie routera:** brak odpowiedzi modemu, błąd HTTP i wygasła sesja (API 125002, 125003, 100003) to stan `MODEM_OFFLINE` – odbiornik zamyka sesję, ponawia logowanie co 15 s, potem co 30 i 60 s, i zapisuje w logu `[MODEM] Polaczenie przywrocone po N s`. Limit czasu nawiązania połączenia 5 s.
- Watchdog nie zabija odbiornika, gdy niedostępny jest tylko modem (restartuje przy `ERROR` lub nieaktualnym heartbeat), więc dłuższa awaria routera nie wyczerpuje limitu 3 restartów na godzinę.
- Panel: stan „Brak połączenia z modemem” (żółty) zamiast „Błąd”; Dashboard i Ustawienia › Modem ostrzegają, gdy wersja odbiornika z heartbeat różni się od wersji panelu (niepełna aktualizacja plików).

## 4.3.0 – 2026-11-24 (minor)
- **Odświeżony wygląd panelu** w tym samym jasnym, niebieskim stylu: ikony liniowe w menu bocznym (inline SVG, bez bibliotek i CDN), znak aplikacji, wyróżnienie aktywnej pozycji paskiem, karty z delikatnym cieniem, nagłówki tabel i podświetlanie wierszy, plakietki statusów z kropką.
- **Telefon i Zebra:** tabele list wyświetlane jako karty z etykietami kolumn (`app.js` dodaje `data-label`, bez JavaScriptu zostaje przewijana tabela). Naprawione przesuwanie całej strony w poziomie na Dashboardzie i w Bazie danych (długie ścieżki).
- Liczniki na Dashboardzie prowadzą do przefiltrowanych list (zgłoszenia według statusu, oczekujące SMS-y), grupy w „Zgłoszenia według grup” do strony grupy; na stronie grupy numery zgłoszeń są linkami, a „Wszystkie zgłoszenia grupy” i „Pokaż także wyłączone” to przyciski w nagłówku karty.
- Czytelne nazwy statusów SMS i wysyłek (Oczekuje, Przyjęty przez modem, Niepoprawny, Błąd, Anulowany…) w plakietkach i filtrach; w bazie bez zmian.
- Plakietka wersji z changelogiem w stopce menu bocznego (na telefonie nie zasłania treści); w stopce login, rola i Wyloguj.
- Nowy ekran logowania i pierwszego uruchomienia (wyśrodkowana karta ze znakiem aplikacji, komunikaty w karcie).
- Dostępność: pola dotyku min. 44 px (menu, rozwijane karty, grupy), widoczny fokus klawiatury na linkach i nagłówkach `<details>`, strzałka przy rozwijanych kartach.

## 4.2.0 – 2026-11-24 (minor)
- **MS SQL Server** jako alternatywa dla SQLite (`db.php`, `docs/MSSQL.md`). W Ustawienia › Baza danych: test połączenia, opcjonalne utworzenie bazy, przełączenie z przeniesieniem obecnych danych (z porównaniem liczby wierszy) albo od pustej bazy (tylko konta), użycie istniejącej bazy i powrót do SQLite. Domyślnie nadal SQLite.
- Odbiornik czyta ustawienia bazy w każdym cyklu: wstrzymuje się na czas przełączania (`data\maintenance.flag`), łączy się z nową bazą bez restartu i ponawia połączenie po awarii serwera SQL.
- Kopie MS SQL: `BACKUP DATABASE ... COPY_ONLY, CHECKSUM` + `RESTORE VERIFYONLY` (zadanie backupu i przycisk w panelu), kontrola `DBCC CHECKDB`. `cli\db.php status|sqlite`.
- Zapytania niezależne od bazy (stronicowanie, `INSERT` z listą kolumn, bez `INSERT OR IGNORE` i `substr`); `config.json` zapisywany atomowo.
- **Menu boczne** zamiast górnego (na telefonie i Zebrze wysuwane przyciskiem Menu, bez JavaScriptu).
- **Grupy zgłoszeń** z podmenu: każda aktywna grupa (np. Pojazdy) ma własną stronę ze statystykami, ostatnimi zgłoszeniami i ustawieniami; lista pojazdów jest na stronie grupy Pojazdy. Stary adres `?p=vehicles` przekierowuje.
- **Ustawienia** z podmenu Autoodpowiedzi, Modem, Baza danych. W Autoodpowiedziach testowy SMS na podany numer (domyślnie autoodpowiedź z przykładowymi danymi) z listą ostatnich testów i ich statusem.

## 4.1.1 – 2026-11-24 (patch)
- XAMPP nie musi być na dysku `C:`. `install.ps1`, `install-watchdog-task.ps1`, `install-backup-task.ps1` i `verify.ps1` same znajdują XAMPP (rejestr, usługa Apache, `X:\xampp` na każdym dysku) albo przyjmują `-Xampp D:\xampp`. Wspólna funkcja w `scripts\_xampp.ps1`.
- `install.ps1` obsługuje tylko świeżą instalację i aktualizację w htdocs. Usunięte przenoszenie danych z `C:\SMS-Awarie`, wyłączanie starych zadań i komentowanie wpisów w `httpd.conf`.
- Kopie przed aktualizacją domyślnie w `<dysk XAMPP>:\SMS-Awarie-Backups` (`-BackupRoot`).
- `watchdog.ps1` ustala `php.exe` względem folderu XAMPP (parametr `-Php` nadal działa); zadanie Watchdoga przekazuje `-Root` i `-Php`.
- Usunięty `scripts\preflight.ps1`.

## 4.1.0 – 2026-11-24 (minor)
- Aplikacja instalowana w standardowym folderze stron XAMPP: `C:\xampp\htdocs\sms-awarie` (adres bez zmian: `http://serwer/sms-awarie/`). Nie trzeba już dopisywać `Alias`/`Include` w `httpd.conf`.
- `index.php` i `assets/` przeniesione z `public/` do katalogu głównego aplikacji. Główny `.htaccess` blokuje dostęp z przeglądarki do wszystkiego poza `index.php` i `assets/`, a `data`, `logs`, `cli`, `views`, `web` mają własne `Require all denied`.
- Dashboard (dla administratora) ostrzega, gdy `data/config.json` jest dostępny z przeglądarki (brak `AllowOverride All`). `scripts\verify.ps1` sprawdza to samo.
- `scripts\install.ps1`: domyślnie instaluje do htdocs, przenosi bazę, `config.json` i kopie z `C:\SMS-Awarie` (stary katalog zostaje nienaruszony), komentuje stare wpisy `Include` z 3.x i 4.0.0, wyłącza zadania wskazujące starą lokalizację.
- `install-watchdog-task.ps1` i `install-backup-task.ps1`: parametry `-Root` i `-Replace`. Watchdog domyślnie pilnuje `C:\xampp\htdocs\sms-awarie\receiver.php`.
- Usunięty `apache-sms-awarie.conf`.
- W repozytorium i paczce aplikacja leży w `htdocs/sms-awarie/` (zamiast `app/`) – ten katalog można skopiować wprost do `C:\xampp\htdocs\`.

## 4.0.0 – 2026-11-23 (major)
- Panel WWW przepisany na czysty PHP + HTML + CSS + vanilla JS, serwowany przez Apache z XAMPP pod adresem `/sms-awarie`. Usunięte: Next.js, React, Node.js, npm, zadanie `SMS-Awarie-v3-Web`, reverse proxy na porcie 8090 i `api.php`.
- Nowy układ: wszystko w `C:\SMS-Awarie` (`config.php` z wersją, `public/` jako jedyny katalog widoczny przez Apache, `views/`, `web/`, `cli/`). Ścieżki względne zamiast `C:/SMS-Awarie` wpisanego w kodzie.
- Jeden instalator `scripts\install.ps1` (podgląd / `-Apply`) dla nowej instalacji i aktualizacji z 3.x: kopia zapasowa, kopiowanie plików, migracja bazy, wpis w `httpd.conf`, wyłączenie panelu 3.x. Usunięte skrypty 02, 04, 10, 11, 12, 15, 20, 21 i `README-DEPLOY.md`.
- Schemat bazy v3: kolejne numery zgłoszeń w roku (`AW/2026/00042`; starsze zachowują numer z 3.x), wyłączanie pojazdów i kont (soft delete), indeks `tickets(plate)`.
- Zgłoszenia: filtry (status, grupa, wyszukiwanie), paginacja, edycja w rozwijanej karcie, eksport CSV (UTF-8 z BOM, `;`). SMS odebrane/wychodzące z filtrami i paginacją.
- Pierwszy administrator zakładany w przeglądarce, gdy w bazie nie ma kont; `cli\reset-password.php` na wypadek zapomnianego hasła. Edycja ról, haseł i wyłączanie kont.
- Ustawienia modemu w panelu: adres, hasło, usuwanie SMS z modemu, liczba SMS na cykl. Zakładka Baza danych: kopia na żądanie, kontrola integralności, lista kopii.
- Daty w formacie DD.MM.RRRR, plakietka wersji z changelogiem, wygląd na PC i telefonach/Zebrach, wydruk przez `@media print`.
- Usunięta migracja do MS SQL (nie obejmowała grup i nie była używana przez odbiornik).

## 3.1.1 – 2026-11-23 (patch)
- Polskie znaki, nawiasy i cudzysłowy w SMS nie blokują już odbiornika ani wysyłki. Treść powiadomień jest transliterowana do ASCII (ą→a), a wiadomość, której nie da się przetworzyć, dostaje status `BLAD` zamiast zatrzymywać pętlę.
- Odebrane SMS-y są usuwane z modemu dopiero po zapisie w bazie (`delete_after_import`, domyślnie włączone). Na Dashboardzie widać zajętość pamięci SMS modemu.
- Zakładka Ustawienia (autoodpowiedzi) synchronizuje się z grupą Pojazdy – zmiana działa w odbiorniku.
- Migracje schematu uruchamiają się raz (`PRAGMA user_version = 2`), a nie przy każdym zapytaniu API. Dodane indeksy.
- Dziennik odbiornika w panelu czyta `logs\receiver-stdout.log` i `receiver-stderr.log`. Watchdog rotuje logi powyżej 5 MB i zachowuje log poprzedniego uruchomienia (`.1`).
- Listy zgłoszeń i grup bez zapytań N+1; listy zgłoszeń, SMS i outbox ograniczone do 1000 najnowszych.
- Do 5 SMS wysyłanych w jednym cyklu (`send_per_cycle`).
- Odbiornik nie startuje z hasłem `CHANGE_ME`; po odrzuconym logowaniu czeka 5 minut. Obsługa logowania `password_type 4` (nowsze firmware Huawei).
- Nowy `backup.php` i `scripts\16-install-backup-task.ps1` – codzienna kopia SQLite z retencją.
- Strefa czasowa `Europe/Warsaw`, `APP_VERSION` i changelog w panelu.
- Frontend: `skipLibCheck` w `tsconfig.json` – bez tego `npm run typecheck` na świeżej instalacji kończył się błędem w typach `lucide-react`.

## 3.1 
- Grupy zgłoszeń z prefiksami, wielu odbiorców, Watchdog v3.
