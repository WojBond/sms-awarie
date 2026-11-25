<?php
// SMS-Awarie - wersja, changelog, strefa czasowa i sciezki. Jedno zrodlo prawdy dla numeru wersji.
define('APP_VERSION', '4.5.0');
const APP_CHANGELOG = [
    '4.5.0' => [
        'Uprawnienia Operatora i Tylko odczyt z podzialem na grupy zgloszen: wszystkie grupy z wybranym poziomem albo tylko wybrane grupy.',
        'Poziomy w grupie: Podglad, Obsluga zgloszen (status i opis), Obsluga i obiekty (np. dodawanie i wylaczanie pojazdow). Tylko odczyt ma zawsze najwyzej Podglad.',
        'Menu, Dashboard, Zgloszenia (z eksportem CSV), Grupy, SMS odebrane i wychodzace pokazuja tylko dozwolone grupy; akcje sprawdzane po stronie serwera.',
        'Uprawnienia dodatkowe konta: wysylka i anulowanie SMS oraz podglad SMS-ow nieprzypisanych do grup.',
        'Ustawianie uprawnien w Uzytkownicy (per konto) i na stronie grupy (Uprawnienia uzytkownikow). Istniejace konta zachowuja dotychczasowy dostep do wszystkich grup.',
    ],
    '4.4.0' => [
        'Nowa strona Ustawienia > Uslugi: stan odbiornika, Watchdoga i kopii zapasowej z przyciskami Zatrzymaj/Uruchom i Wstrzymaj/Wznow.',
        'Przycisk "Zatrzymaj wszystko" przed aktualizacja (odbiornik konczy biezacy krok i sie zatrzymuje, Watchdog i backup wstrzymane) oraz "Wznow wszystko".',
        'Zadania SMS-Awarie w Harmonogramie zadan Windows: stan, ostatnie i nastepne uruchomienie, Wylacz/Wlacz i Uruchom teraz (gdy Apache ma uprawnienia administratora; inaczej panel podaje polecenie PowerShell).',
        'Zatrzymany odbiornik nie jest uruchamiany przez Watchdoga; wstrzymany backup pomija kopie bez bledu.',
    ],
    '4.3.1' => [
        'Naprawiony blad krytyczny odbiornika db(): Argument #1 must be of type ?array po aktualizacji z 4.1 (stary receiver.php z nowym db.php) - db() przyjmuje tez dawne wywolanie db(\'sqlite\', $cfg).',
        'Odbiornik sam wznawia polaczenie z modemem Huawei po jego restarcie: ponawia co 15-60 s, loguje przywrocenie polaczenia, a w panelu pokazuje stan "Brak polaczenia z modemem" zamiast bledu.',
        'Watchdog nie restartuje juz odbiornika, gdy niedostepny jest tylko modem (limit 3 restartow na godzine nie jest zuzywany przy dluzszej awarii routera).',
        'Dashboard i Ustawienia > Modem ostrzegaja, gdy odbiornik dziala w innej wersji niz panel.',
    ],
    '4.3.0' => [
        'Odswiezony wyglad panelu: ikony w menu bocznym, znak aplikacji, karty z cieniem, kolorowe paski statusow na licznikach.',
        'Na telefonie i Zebrze listy (Dashboard, SMS, wysylki, grupy, dziennik) jako karty zamiast tabel przewijanych w bok; strona nie przesuwa sie juz w poziomie.',
        'Liczniki na Dashboardzie i grupy prowadza do przefiltrowanych list; numery zgloszen na stronie grupy sa linkami.',
        'Czytelne nazwy statusow (Oczekuje, Przyjety przez modem, Niepoprawny, Blad) zamiast kodow, takze w filtrach.',
        'Plakietka wersji w stopce menu bocznego (nie zaslania tresci); uzytkownik z rola i przycisk Wyloguj z ikona.',
        'Nowy ekran logowania i pierwszego uruchomienia; wieksze pola dotyku i widoczny fokus klawiatury.',
    ],
    '4.2.0' => [
        'Baza MS SQL Server jako alternatywa dla SQLite: Ustawienia > Baza danych - test polaczenia, przelaczenie z przeniesieniem obecnych danych albo od pustej bazy, powrot do SQLite.',
        'Odbiornik laczy sie z nowa baza bez restartu i wstrzymuje sie na czas przelaczania; kopie MS SQL przez BACKUP DATABASE (COPY_ONLY) z weryfikacja.',
        'Menu boczne zamiast gornego; na telefonie wysuwane przyciskiem Menu.',
        'Grupy zgloszen w podmenu (np. Pojazdy) - strona grupy ze statystykami, ostatnimi zgloszeniami, lista pojazdow i ustawieniami.',
        'Ustawienia z podmenu Autoodpowiedzi, Modem, Baza danych; testowy SMS na podany numer w Autoodpowiedziach.',
    ],
    '4.1.1' => [
        'Instalator i zadania same znajduja XAMPP na dowolnym dysku (rejestr, usluga Apache, X:\\xampp) albo przyjmuja parametr -Xampp D:\\xampp.',
        'Instalator tylko dla swiezej instalacji i aktualizacji w htdocs - bez przenoszenia danych z C:\\SMS-Awarie i bez zmian w httpd.conf.',
        'Watchdog sam ustala php.exe wzgledem folderu XAMPP; usuniety preflight.ps1.',
    ],
    '4.1.0' => [
        'Instalacja w standardowym folderze XAMPP: C:\\xampp\\htdocs\\sms-awarie (bez Alias w httpd.conf).',
        'Ochrona plikow przez .htaccess: z przegladarki dostepne tylko index.php i assets; ostrzezenie na Dashboardzie, gdy katalog data jest odsloniety.',
        'Instalator przenosi dane z C:\\SMS-Awarie i wylacza stare zadania; zadania Watchdoga i backupu z parametrem -Root i -Replace.',
    ],
    '4.0.0' => [
        'Panel w czystym PHP serwowany przez Apache z XAMPP (adres /sms-awarie) - bez Node.js, npm, Next.js i proxy na porcie 8090.',
        'Jeden instalator scripts\install.ps1 (nowa instalacja i aktualizacja z 3.x).',
        'Kolejne numery zgloszen w roku (AW/2026/00042); starsze zachowuja dotychczasowy numer.',
        'Pojazdy mozna wylaczac (soft delete) zamiast tylko dodawac.',
        'Filtry, wyszukiwanie, paginacja i eksport CSV zgloszen; daty DD.MM.RRRR.',
        'Pierwszy administrator zakladany w przegladarce, gdy brak kont.',
        'Usunieta migracja do MS SQL (nieuzywana przez odbiornik).',
    ],
    '3.1.1' => [
        'Polskie znaki i nawiasy w SMS nie blokuja juz odbiornika (transliteracja, bledna wiadomosc dostaje status BLAD).',
        'Odebrane SMS-y sa usuwane z modemu po zapisie w bazie; zajetosc pamieci modemu na Dashboardzie.',
        'Ustawienia autoodpowiedzi dzialaja dla grupy Pojazdy.',
        'Schemat bazy sprawdzany raz zamiast przy kazdym zapytaniu; indeksy.',
        'Dziennik odbiornika w panelu, rotacja logow Watchdoga, codzienny backup SQLite.',
    ],
];
date_default_timezone_set('Europe/Warsaw');
define('APP_ROOT', __DIR__);
define('DATA_DIR', __DIR__.'/data');
define('LOG_DIR', __DIR__.'/logs');
