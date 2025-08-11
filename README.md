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

### Kärnfunktioner (MVP)
- Information från TMDB.
- Betygsskala 1–5 + kort kommentar (valfritt)
- Följ vänner, aktivitetsflöde
- Delning av profil och listor (offentligt eller via länk)
- Enkla listor
    - Egna listor
    - Delade listor (fler än en person kan ändra)
    - Följa andras listor
    - Varje titel kan ha flera tillstånd (sett, vill se, tittar nu, slutat titta)

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
- v0.1 MVP: sök, listor, användare, tillstånd på titlar, kommentarer, betyg, historik
- v0.2 Gemensamma listor, följ listor, följ personer, delningar
- v0.3 JustWatch, smarta listor, rekommendationer

### Licens
- GPL-3.0-or-later

## Tech stack och arkitektur
- **Backend**: PHP 8.3, Slim 4 (`slim/slim`), PSR-7 (`slim/psr7`), `vlucas/phpdotenv`, `guzzlehttp/guzzle`
- **Database**: MariaDB (MySQL) via PDO; UTF-8 (`utf8mb4_unicode_ci`)
- **Frontend**: Tailwind CSS (CDN), Alpine.js (CDN), minimal vanilla JS
- **Hosting**: Apache/PHP-FPM on shared hosting or `php -S` for local dev

### Simplicity Constraints
- No build step (CDN assets), no queues/workers, no microservices
- Small codebase, minimal dependencies, straightforward CRUD

## Future Refactoring Ideas

### 1. Extract JavaScript to Separate File
**Current:** Inline JavaScript in PHP strings within index.php  
**Future:** Move to `public/js/app.js` for better syntax highlighting and editing  
**Benefit:** Cleaner separation, easier JavaScript development  
**Note:** No build step required - simple `<script src="/js/app.js">` include

### 2. Move HTML to Template Files
**Current:** HTML generation functions in index.php  
**Future:** Create `templates/` directory with separate `.php` template files  
**Benefit:** Better HTML syntax highlighting, easier template editing  
**Implementation:** Simple PHP includes, no templating engine needed

### 3. Add Validation Helper Class
**Current:** Scattered validation logic in route handlers  
**Future:** Centralized `Validator` class with reusable validation methods  
**Benefit:** Consistent error messages, DRY principle, cleaner routes  
**Example:** `Validator::required()`, `Validator::email()`, etc.

### 4. Extract Service Layer
**Current:** Business logic mixed in route handlers  
**Future:** Service classes for complex operations (TitleService, ListService)  
**Benefit:** Testable business logic, reusable operations, cleaner routes  
**When:** Consider when individual routes exceed ~50 lines or logic gets complex
