# UnoRank: backend i baza danych

Etapy pierwszy i drugi: Laravel 13, PHP 8.3, MySQL 8.4 z InnoDB i uwierzytelnianie aplikacji Android przez Laravel Sanctum.

## Zakres

1. Logowanie administratora, sprawdzenie konta i wylogowanie bieżącego urządzenia.
2. Lista graczy z paginacją oraz filtrem aktywności.
3. Dodawanie, edycja i dezaktywacja gracza. Nick może zastępować imię i pierwszą literę nazwiska.
4. Zapis i edycja rozgrywek, także archiwalnych, oraz obsługa ponownego wysłania tej samej gry.
5. Publiczna historia, ranking ogólny, ranking dnia, kategorie i statystyki graczy.
6. Testy funkcjonalne i zabezpieczeń oraz konfiguracja CI z MySQL 8.4.

## Uruchomienie lokalne na Linuxie

Wymagania: Docker Engine z Compose v2, Git i OpenSSL.

```sh
./bin/setup.sh
docker compose exec app php artisan unorank:create-admin admin
```

Pierwsze polecenie tworzy lokalny plik `.env` z losowym kluczem i hasłami bazy, buduje kontenery, czeka na ich gotowość i uruchamia migracje. Istniejący `.env` pozostaje bez zmian. Drugie pyta o hasło administratora bez wyświetlania go. Hasło ma przynajmniej 12 znaków, litery i cyfry. Login zawiera małe litery, cyfry, kropki, podkreślenia lub łączniki. Nie ma publicznej rejestracji ani fabrycznego konta.

API: `http://localhost:8000/api/v1`. Kontrola działania: `http://localhost:8000/up`.

Konfiguracja Docker jest przeznaczona do lokalnego rozwoju. Serwer jest dostępny tylko na komputerze lokalnym. Test telefonu w sieci lokalnej i wdrożenie z HTTPS przygotujemy osobno. MySQL nie udostępnia portu na hoście. Zwykłe `docker compose down` zachowuje dane w wolumenie. Nie używaj opcji `--volumes`, jeśli chcesz zachować dane.

Po zmianie kodu: `docker compose up -d --build`. Migracje: `docker compose exec app php artisan migrate`.

## API

Wszystkie żądania: `Accept: application/json`. JSON przesyłamy z `Content-Type: application/json`.

| Metoda | Adres | Zastosowanie |
| :--- | :--- | :--- |
| POST | `/api/v1/auth/login` | Login, hasło i nazwa urządzenia |
| GET | `/api/v1/auth/me` | Bieżący administrator |
| POST | `/api/v1/auth/logout` | Unieważnienie tokenu bieżącego urządzenia |
| GET | `/api/v1/players` | Lista wszystkich graczy |
| GET | `/api/v1/players?active=1&per_page=100` | Aktywni gracze do nowej gry |
| POST | `/api/v1/players` | Dodanie gracza |
| PATCH | `/api/v1/players/{id}` | Zmiana wskazanych pól gracza |

Przykładowe logowanie:

```json
{"username":"admin","password":"TWOJE_HASLO","device_name":"Telefon administratora"}
```

Odpowiedź zawiera `token`, `token_type` i `expires_at`. Kolejne żądania wymagają nagłówka `Authorization: Bearer TOKEN`. Token wygasa po 30 dniach. Po wygaśnięciu ponowne logowanie będzie wymagane przed synchronizacją. Nie dotyczy to lokalnego wznowienia sesji na telefonie, które powstanie w aplikacji Android.

Przykładowy gracz:

```json
{"first_name":"Jan","last_name":"Nowak","nickname":"Janek","use_nickname":true,"is_active":true}
```

Imię i nazwisko są obowiązkowe. Nick jest wymagany, gdy `use_nickname` ma wartość `true`. Dezaktywacja: `{"is_active":false}`. Nie usuwamy graczy, aby zachować powiązania z historią. Pełne nazwiska są dostępne wyłącznie przez chronione API administratora. Publiczne statystyki mają osobne odpowiedzi zawierające tylko wybraną nazwę gracza.

Lista jest stronicowana: pobierający kompletny zestaw graczy powinien podążać za `links.next` aż do wartości `null`. Maksymalny rozmiar strony wynosi 100.

Błędy: 401 oznacza brak poprawnego logowania, 403 brak uprawnień lub nieaktywne konto administratora, 422 błędne dane, 429 przekroczony limit żądań. Limit logowania: 5 prób na minutę dla loginu i adresu IP oraz 30 dla adresu IP. Pozostałe API: 120 żądań na minutę na administratora.

## Struktura danych

`users`: wyłącznie konta administratorów, oddzielone od graczy.

`players`: dane graczy i ustawienia wyświetlania nazwy.

`games`: identyfikator UUID do synchronizacji, data gry, numer dnia, liczba graczy, status i autor zapisu. Data oznacza dzień rozgrywki, a nie wysłania na serwer.

`game_participants`: gracz, kolejność rozpoczynania i miejsce. Puste miejsce pozwala zapisać przerwaną grę. Baza blokuje powtórzenie gracza, miejsca i kolejności w ramach jednej gry.

API sprawdza kompletność wyników i limit od 2 do 15 graczy. Każdy zapis gry i uczestników odbywa się w jednej transakcji. `revision` służy do ochrony przed nadpisaniem nowszej edycji. `submission_hash` przechowuje skrót pierwszego zgłoszenia, aby późniejsze ponowienie wysyłki nie cofnęło edycji.

## Testy

Osobna, tymczasowa baza MySQL do testów:

```sh
docker compose --profile test run --rm test
```

Testy nie korzystają z bazy aplikacji. CI uruchamia ten sam zestaw na MySQL 8.4 oraz buduje i sprawdza konfigurację Docker.

Bez Dockera, przy dostępnym PHP 8.3 z wymaganymi rozszerzeniami i Composerze:

```sh
composer install
php artisan test
vendor/bin/pint --test
```

Domyślnie testy lokalne używają SQLite w pamięci. Wymagane są między innymi rozszerzenia mbstring, dom, xml, xmlwriter, pdo_sqlite i pdo_mysql. Dane demonstracyjne nie są automatycznie dodawane do działającej bazy.

## Rozgrywki i synchronizacja

Te adresy wymagają tokenu aktywnego administratora:

| Metoda | Adres | Zastosowanie |
| :--- | :--- | :--- |
| POST | `/api/v1/games` | Zapis gry lub ponowienie wysyłki |
| GET | `/api/v1/games` | Historia do edycji |
| GET | `/api/v1/games/{uuid}` | Aktualny wynik i wersja |
| PUT | `/api/v1/games/{uuid}` | Pełna edycja istniejącej gry |

Przykładowy zapis, również dla archiwalnej rozgrywki:

```json
{
  "id": "43420188-82fb-422f-aab0-66d30d6de143",
  "played_on": "2026-10-18",
  "daily_number": 1,
  "player_count": 2,
  "status": "completed",
  "participants": [
    {"player_id": 1, "starting_order": 1, "place": 2},
    {"player_id": 2, "starting_order": 2, "place": 1}
  ]
}
```

Identyfikator UUID nadaje telefon raz, przed pierwszą wysyłką. `played_on` oznacza lokalny dzień gry w strefie Europe/Warsaw, bez dat przyszłych. `daily_number` jest unikalny w obrębie dnia dla całego systemu. Identyfikatory graczy muszą istnieć. Nieaktywni gracze mogą wystąpić w archiwalnym wyniku lub grze wysłanej po okresie offline.

`starting_order` musi tworzyć pełną kolejność od 1 do liczby uczestników. Przy `completed` pola `place` również tworzą pełny zestaw miejsc. Przy `interrupted` miejsce może być `null`, lecz każde podane miejsce nadal musi być unikalne i mieścić się w liczbie graczy. Cała przerwana gra jest pomijana w statystykach. Zachowaną kolejność rozpoczynania można wykorzystać do kontynuacji.

Pierwszy zapis zwraca 201. Ponowienie tych samych danych przez tego samego administratora zwraca 200 i aktualny zapis. Kolejność elementów tablicy uczestników nie ma znaczenia. Zmiana wyniku wymaga PUT. Identyfikator, autor i skrót pierwotnego zgłoszenia nie zmieniają się przy edycji.

Edycja PUT przesyła pełne dane jak powyżej, bez `id`, z dodatkowym polem `revision` odczytanym z serwera. Po zapisie wersja wzrasta o 1. Dotyczy to także poprawienia daty, liczby graczy, uczestników i statusu. Po utracie odpowiedzi na PUT należy odczytać grę i porównać dane przed kolejną próbą.

Konflikty zwracają HTTP 409 oraz `code` i `message`:

| Kod | Znaczenie i działanie telefonu |
| :--- | :--- |
| `game_id_conflict` | UUID jest już użyty przez inny zapis. Pobierz istniejącą grę i pokaż konflikt administratorowi. |
| `daily_number_conflict` | Numer w danym dniu jest zajęty. Pobierz historię dnia i zaproponuj wolny numer do zatwierdzenia. |
| `revision_conflict` | Ktoś już edytował wynik. Pobierz aktualną wersję i pokaż różnice przed ponownym zapisaniem. |

Przy błędzie telefon zachowuje lokalny wynik. API nie usuwa gier. Trwała kolejka offline, ekran konfliktów i wybór kolejności rozpoczynania powstaną w projekcie Android.

## Publiczne wyniki

Wszystkie poniższe adresy są dostępne bez logowania. Limit: 120 żądań na minutę na adres IP. Dane odpowiedzi nie zawierają kont administratorów, skrótów zgłoszeń ani pełnych nazwisk graczy. Nazwa gracza jest wyliczana z aktualnych ustawień jego profilu.

| Metoda | Adres | Zastosowanie |
| :--- | :--- | :--- |
| GET | `/api/v1/public/games` | Historia od najnowszej daty i numeru |
| GET | `/api/v1/public/games/{uuid}` | Szczegóły i miejsca uczestników |
| GET | `/api/v1/public/rankings` | Ranking ogólny |
| GET | `/api/v1/public/rankings?date=2026-10-18` | Najlepsi gracze danego dnia |
| GET | `/api/v1/public/rankings?player_count=4` | Ranking gier dokładnie czteroosobowych |
| GET | `/api/v1/public/players/{id}/statistics` | Statystyki jednego gracza |

Filtry `date` i `player_count` można łączyć dla historii, rankingu i statystyk gracza. Historia dodatkowo obsługuje `status=completed` lub `status=interrupted`. Listy obsługują `page` oraz `per_page`, domyślnie 20, maksymalnie 100. Historia zwraca paginację w `links` i `meta`; ranking zwraca `total`, `current_page`, `next_page_url` i pozostałe pola standardowego paginatora, obok `data`.

Wynik kategorii: średnia miejsc, zaokrąglona do dwóch miejsc. Wynik ogólny: średnia tych zaokrąglonych wyników kategorii, ponownie zaokrąglona do dwóch miejsc. Kategorie mają jednakową wagę. Wynik jest liczbą JSON; dwa miejsca po przecinku formatuje interfejs. Przykład z ustaleń: 1,50, 1,67 i 8,00 daje 3,72 przy sześciu rozegranych grach.

Niższy wynik jest lepszy. Przy równym wyniku wyżej jest gracz z większą liczbą zakończonych gier w wybranym zakresie. Przy obu wartościach równych gracze współdzielą miejsce, np. 1, 1, 3. Stabilne uporządkowanie identyfikatorem nie rozstrzyga remisu. W rankingu dnia `is_day_leader` wskazuje wszystkich liderów.

Ranking ogólny zawiera także graczy bez zakończonych gier: na końcu, z `score: null` i `rank: null`. Ranking filtrowany dniem lub kategorią zawiera tylko osoby z zakończonymi grami w tym zakresie. Nieaktywni gracze zachowują historyczne wyniki.

Statystyki zawierają liczbę gier, pierwszych i drugich miejsc, histogram zajętych miejsc oraz zestawienie kategorii. Histogram pomija miejsca z liczbą wystąpień równą zero. Edycja wyniku jest uwzględniana przy następnym pobraniu statystyk, bez osobnego przeliczania i bez utrzymywania drugiej kopii rankingu.
