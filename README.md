## tvsoffan

En social tittar‑logg för film och tv: spara vad du sett/vill se, betygsätt med en snabb kommentar och se vad vännerna tittar på. Låg friktion, hög delningsglädje.

### Varför
Det är svårt att minnas vad man sett, vad man vill se härnäst och vad kompisar faktiskt rekommenderar. Långa recensioner blir ett hinder; nätverkseffekter kräver enkel loggning och delning.

### För vem
- Personer som vill hålla koll på sitt tittande utan krångel
- Vängrupper som vill upptäcka via varandra
- Communities som vill kurera listor

### Fokusprinciper
- Socialt först: bygg funktioner som leder till delningar, följande och samtal
- Friktion minimalt: en handling ska räcka (t.ex. “Sett” + betyg)
- Snabba omdömen > långa recensioner
- Standard: publik profil, privata listor möjliga (opt‑out/opt‑in tydligt)
- Starta enkelt, skala senare (MVP före allt)
- Bygg på befintliga tjänster (TMDB, JustWatch, etc.)
- Fokus på listor - egna och delade

### Icke‑mål
- Inget tungt reviewsystem med långa texter, wikis eller forum
- Ingen komplex katalog/metadata‑redigering av titlar
- Ingen pirat-/streaming‑länk‑indexering

### Kärnfunktioner (MVP)
- Logga titlar: Sett/Vill se. Information från TMDB.
- Betygsskala 1–5 + kort kommentar (valfritt)
- Följ vänner, aktivitetsflöde
- Delning av profil och listor (offentligt eller via länk)
- Enkla listor
    - En egen lista
    - Delade listor (fler än en person kan ändra)
    - Följa andras listor
    - Varje titel kan ha flera tillstånd (sett, vill se, tittar nu, slutat titta, etc.)

### Prioriterade utökningar
- Must‑have: JustWatch för att lätt se vilken streamingtjänst som har en titel
- Should‑have: smarta listor (populärt hos vänner)

### Centrala användarflöden
- Lägg till: Sök → Välj titel → Sett/Vill se/tittar nu → (valfritt) betyg + snabb kommentar  
- Läs om en titel: titel, år, genre, betyg, recensioner, trailer, kommentarer och betyg från vänner
- Skapa lista: Skapa en egen lista med namn -> ev bjud in andra till listan -> ev dela listan
- Ändra lista: namn, privat/offentlig. Om den görs privat så bryts delningar.
- Radera lista: tar bort en egen lista. Om den är gemensam så tas den bara den personen bort från listan.
- Följ lista: följ en annan persons lista
- Följ person: följ en annan persons profil – om den är offentlig. Då kommer man att se alla listor och titlar som den personen har som är offentliga. Man kommer dessutom att se den personens betyg och kommentarer på titlar.
- Upptäck: “Vänner tittar på” → Spara till “Vill se”  
- Upptäck: Från annans lista → Spara till “Vill se”

### Inloggning och användare
- Signup & login: 
    - Email, lösenord, namn
    - Google/Apple/Facebook/Microsoft/etc.

### Integritet & delning
- Profil: offentlig som standard (kan göras privat)
- Listor: privata, olistade (länk), eller offentliga

### Mätetal (produkt)
- DAU/WAU och retention (W1/W4)
- Antal loggningar per aktiv användare/vecka
- Delningar per användare/vecka
- Andel följ‑relationer (densitet i nätverket)

### Roadmap (kort)
- v0.1 MVP: sök, listor, användare, delningar, tillstånd på titlar
- v0.2 Gemensamma listor, följ listor, följ personer, kommentarer, betyg
- v0.3 JustWatch, smarta listor, rekommendationer

### Installation & utveckling
- Status: under uppstart. Lägg till lokala körsteg här när stacken är satt.

### Licens
- GPLv3

## Tech stack och arkitektur
- **Backend**: PHP 8.3, Slim 4 (`slim/slim`), PSR-7 (`slim/psr7`), `vlucas/phpdotenv`, `guzzlehttp/guzzle`
- **Database**: MariaDB (MySQL) via PDO; UTF-8 (`utf8mb4_unicode_ci`)
- **Frontend**: Tailwind CSS (CDN), Alpine.js (CDN), minimal vanilla JS
- **Hosting**: Apache/PHP-FPM on shared hosting or `php -S` for local dev

### Simplicity Constraints
- No build step (CDN assets), no queues/workers, no microservices
- Small codebase, minimal dependencies, straightforward CRUD
