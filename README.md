# UnoRank: backend i baza danych

Pierwszy etap: Laravel 13, PHP 8.3, MySQL 8.4 z InnoDB i uwierzytelnianie aplikacji Android przez Laravel Sanctum.

## Zakres

1. Logowanie administratora, sprawdzenie konta i wylogowanie bieżącego urządzenia.
2. Lista graczy z paginacją oraz filtrem aktywności.
3. Dodawanie, edycja i dezaktywacja gracza. Nick może zastępować imię i pierwszą literę nazwiska.
4. Tabele rozgrywek i uczestników: podstawa kolejnego etapu. API wyników, synchronizacja i ranking nie są jeszcze udostępnione.
5. Testy funkcjonalne i zabezpieczeń oraz konfiguracja CI z MySQL 8.4.

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

Imię i nazwisko są obowiązkowe. Nick jest wymagany, gdy `use_nickname` ma wartość `true`. Dezaktywacja: `{"is_active":false}`. Nie usuwamy graczy, aby zachować powiązania z historią. Pełne nazwiska są dostępne wyłącznie przez chronione API administratora. Publiczne statystyki będą mieć osobne odpowiedzi.

Lista jest stronicowana: pobierający kompletny zestaw graczy powinien podążać za `links.next` aż do wartości `null`. Maksymalny rozmiar strony wynosi 100.

Błędy: 401 oznacza brak poprawnego logowania, 403 brak uprawnień lub nieaktywne konto administratora, 422 błędne dane, 429 przekroczony limit żądań. Limit logowania: 5 prób na minutę dla loginu i adresu IP oraz 30 dla adresu IP. Pozostałe API: 120 żądań na minutę na administratora.

## Struktura danych

`users`: wyłącznie konta administratorów, oddzielone od graczy.

`players`: dane graczy i ustawienia wyświetlania nazwy.

`games`: identyfikator UUID do przyszłej synchronizacji, data gry, numer dnia, liczba graczy, status i autor zapisu. Data oznacza dzień rozgrywki, a nie wysłania na serwer.

`game_participants`: gracz, kolejność rozpoczynania i miejsce. Puste miejsce pozwala zapisać przerwaną grę. Baza blokuje powtórzenie gracza, miejsca i kolejności w ramach jednej gry.

Walidacja kompletności wyników, limitu 2 do 15 graczy, obliczenia rankingu i rozwiązywanie konfliktów numerów podczas synchronizacji należą do kolejnego etapu. Obecnie nie ma endpointu zapisującego rozgrywki.

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
