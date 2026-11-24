<?php
// SMS-Awarie - wersja, changelog, strefa czasowa i sciezki. Jedno zrodlo prawdy dla numeru wersji.
define('APP_VERSION', '4.0.0');
const APP_CHANGELOG = [
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
