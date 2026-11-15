# VTC Taken

WordPress plugin voor VTC Woerden om taken van teams te beheren en te tonen: algemene taken, bardiensten, en tellen/fluiten bij thuiswedstrijden. Vervangt de oude [wedstrijd planner](https://github.com/VTC-Woerden/wp-plugin-wedstrijd-planner).

## Wat zit erin

- **Taken > Alle taken / Nieuwe taak**: taken per team (Algemeen, Bardienst), met datum, tijd en optionele eindtijd. Filteren op team en type, en exporteren naar Excel.
- **Taken > Wedstrijden**: thuiswedstrijden uit Nevobo ophalen en per wedstrijd een team kiezen voor tellen en fluiten. Met een takenoverzicht per team, een teller per dag, de uitwedstrijden van die dag, en controles (rood als een team zelf speelt, oranje als een team die dag niet of uit speelt).
- **Taken > Instellingen**: verborgen poules, tweede scheidsrechter, alleen tellers, de minimale tijd tussen eigen wedstrijd en taak, teams koppelen aan Nevobo, en gegevens overnemen uit de oude wedstrijd planner.
- **Rol "Takenbeheerder"**: kan alleen het Taken menu gebruiken.

## Op de website

- Shortcode `[vtc_taken]`: de taken van een team als tabel, met een printknop.
  - Met `?team=heren-1` in de URL (zoals de teampagina `/teams/?team=heren-1`): de taken van dat team.
  - Op een pagina van het `teams` post type: de taken van dat team. Elders: alle teams.
  - `team="heren-1"`, `toon_verleden="ja"`, `print="nee"`
  - Het `teams` post type heeft geen editor, zet daarom in de template van een teampagina: `<?php echo do_shortcode('[vtc_taken]'); ?>`
- Schermen voor in de zaal (zonder thema):
  - `/?vtc_scherm=wedstrijddag&datum=2026-11-21`
  - `/?vtc_scherm=weekschema&weeknummer=47&jaar=2026&columns=2` (met bardiensten)
  - Na het uitschakelen van de oude wedstrijd planner werken ook `/wedstrijddag/` en `/weekschema/` weer.

## Vereisten

- Het `teams` post type (komt uit het thema van de website).
- Nevobo exports van sporthal `WOEPO` en vereniging `CKL9X7N` (zie `plugin.php`).

## Database

Eigen tabellen `wp_vtc_tasks` en `wp_vtc_matches`. Ze worden bij het activeren aangemaakt en bij een update automatisch bijgewerkt (`VTC_TAKEN_DB_VERSION` in `plugin.php`).

## Deploy

`.github/workflows/deploy.yaml` zet de plugin via SFTP op de server (handmatig starten). Nodig in de repository secrets: `SFTP_HOST`, `SFTP_PORT`, `SFTP_USER`, `SFTP_PASSWORD`, `SFTP_REMOTE_DIR`.

`lib/` bevat SimpleXLSX en SimpleXLSXGen (MIT) voor het lezen en schrijven van Excel bestanden.
