<?php
declare(strict_types=1);

function lower_text(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($trimmed, 'UTF-8');
    }
    return strtolower($trimmed);
}

function training_template_bank_file(): string
{
    return dirname(__DIR__) . '/assets/content/dtz_lesen_hoeren_template_bank.json';
}

function load_training_template_bank(): array
{
    $file = training_template_bank_file();
    if (!is_file($file)) {
        throw new RuntimeException('Template-Bank wurde nicht gefunden.');
    }

    $raw = file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') {
        throw new RuntimeException('Template-Bank ist leer.');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Template-Bank enthält ungültiges JSON.');
    }

    return $decoded;
}

function normalize_training_module(string $module): string
{
    $value = lower_text($module);
    if ($value === 'lesen') {
        return 'lesen';
    }
    if ($value === 'hoeren' || $value === 'hören') {
        return 'hoeren';
    }
    return '';
}

function normalize_training_teil(string $module, string $teilRaw): int
{
    $normalizedModule = normalize_training_module($module);
    if ($normalizedModule === '') {
        return -1;
    }

    $value = lower_text($teilRaw);
    if ($value === '' || $value === '0' || $value === 'all' || $value === 'alle') {
        return 0;
    }

    if (preg_match('/^[lh]?([1-9]\d*)$/u', $value, $m) !== 1) {
        return -1;
    }

    $teil = (int)$m[1];
    $maxTeil = $normalizedModule === 'lesen' ? 5 : 4;
    if ($teil < 1 || $teil > $maxTeil) {
        return -1;
    }

    return $teil;
}

function germanize_umlauts_text(string $text): string
{
    if ($text === '') {
        return '';
    }

    return str_replace(
        ['Ae', 'Oe', 'Ue', 'ae', 'oe', 'ue'],
        ['Ä', 'Ö', 'Ü', 'ä', 'ö', 'ü'],
        $text
    );
}

function build_clean_hoeren_templates(): array
{
    $entries = [
        [
            'id' => 'hoeren_clean_01',
            'dtz_part' => 'H1 Kurze Ansagen',
            'task_type' => 'Telefonansage',
            'context' => 'Arztpraxis',
            'title' => 'Terminverschiebung',
            'audio_script' => 'Guten Tag, hier ist die Arztpraxis Keller. Ihr Termin morgen faellt aus. Der neue Termin ist am Mittwoch um 9:15 Uhr. Bitte bringen Sie Ihre Versichertenkarte mit.',
            'question' => 'Wann ist der neue Termin?',
            'options' => ['A' => 'am Mittwoch um 9:15 Uhr', 'B' => 'am Mittwoch um 11:15 Uhr', 'C' => 'am Freitag um 9:15 Uhr'],
            'correct' => 'A',
            'rationale' => 'Im Hoertext steht: Der neue Termin ist am Mittwoch um 9:15 Uhr.',
        ],
        [
            'id' => 'hoeren_clean_02',
            'dtz_part' => 'H1 Kurze Ansagen',
            'task_type' => 'Kursinformation',
            'context' => 'Sprachschule',
            'title' => 'Raumwechsel',
            'audio_script' => 'Achtung, der Integrationskurs beginnt heute nicht in Raum 3, sondern in Raum 5. Der Unterricht startet wie immer um 18 Uhr.',
            'question' => 'Was hat sich geaendert?',
            'options' => ['A' => 'Die Uhrzeit hat sich geaendert.', 'B' => 'Der Kurs findet in einem anderen Raum statt.', 'C' => 'Der Kurs faellt heute aus.'],
            'correct' => 'B',
            'rationale' => 'Die Uhrzeit bleibt gleich, nur der Raum ist neu.',
        ],
        [
            'id' => 'hoeren_clean_03',
            'dtz_part' => 'H1 Kurze Ansagen',
            'task_type' => 'Behördenhinweis',
            'context' => 'Buergeramt',
            'title' => 'Unterlagen',
            'audio_script' => 'Guten Tag. Bitte kommen Sie morgen um 10 Uhr zum Buergeramt. Fuer den Termin brauchen Sie Ihren Ausweis und ein Passfoto.',
            'question' => 'Welche Unterlagen brauchen Sie?',
            'options' => ['A' => 'nur den Ausweis', 'B' => 'Ausweis und Passfoto', 'C' => 'nur das Passfoto'],
            'correct' => 'B',
            'rationale' => 'Es werden zwei Unterlagen genannt: Ausweis und Passfoto.',
        ],
        [
            'id' => 'hoeren_clean_04',
            'dtz_part' => 'H1 Kurze Ansagen',
            'task_type' => 'Arbeit',
            'context' => 'Firma',
            'title' => 'Schichttausch',
            'audio_script' => 'Hallo Frau Yilmaz, ich kann am Freitag die Spaetschicht nicht machen. Koennen Sie bitte meine Schicht von 14 bis 22 Uhr uebernehmen?',
            'question' => 'Welche Schicht soll uebernommen werden?',
            'options' => ['A' => 'Fruehschicht 6 bis 14 Uhr', 'B' => 'Nachtschicht 22 bis 6 Uhr', 'C' => 'Spaetschicht 14 bis 22 Uhr'],
            'correct' => 'C',
            'rationale' => 'Im Text steht klar: von 14 bis 22 Uhr.',
        ],
        [
            'id' => 'hoeren_clean_05',
            'dtz_part' => 'H1 Kurze Ansagen',
            'task_type' => 'Apotheke',
            'context' => 'Bestellung',
            'title' => 'Medikament abholbereit',
            'audio_script' => 'Guten Abend, hier ist die Adler-Apotheke. Ihr Medikament ist jetzt da. Sie koennen es heute bis 19 Uhr abholen.',
            'question' => 'Bis wann kann das Medikament abgeholt werden?',
            'options' => ['A' => 'bis 18 Uhr', 'B' => 'bis 19 Uhr', 'C' => 'bis 20 Uhr'],
            'correct' => 'B',
            'rationale' => 'Die Ansage nennt: heute bis 19 Uhr.',
        ],
        [
            'id' => 'hoeren_clean_06',
            'dtz_part' => 'H1 Kurze Ansagen',
            'task_type' => 'Wohnung',
            'context' => 'Hausverwaltung',
            'title' => 'Handwerkertermin',
            'audio_script' => 'Guten Tag, hier ist die Hausverwaltung. Der Heizungsmonteur kommt am Donnerstag zwischen 8 und 10 Uhr. Bitte bleiben Sie in dieser Zeit zu Hause.',
            'question' => 'Wann kommt der Monteur?',
            'options' => ['A' => 'Donnerstag zwischen 8 und 10 Uhr', 'B' => 'Donnerstag zwischen 10 und 12 Uhr', 'C' => 'Freitag zwischen 8 und 10 Uhr'],
            'correct' => 'A',
            'rationale' => 'Die genaue Zeit wird mit Donnerstag 8 bis 10 Uhr genannt.',
        ],
        [
            'id' => 'hoeren_clean_07',
            'dtz_part' => 'H2 Dialoge',
            'task_type' => 'Dialog',
            'context' => 'Bahnreise',
            'title' => 'Verspaetung',
            'audio_script' => 'A: Entschuldigung, faehrt der Zug nach Koeln puenktlich? B: Nein, er hat etwa 20 Minuten Verspaetung. A: Danke, dann warte ich noch am Gleis 7.',
            'question' => 'Wie spaet ist der Zug?',
            'options' => ['A' => 'etwa 10 Minuten', 'B' => 'etwa 20 Minuten', 'C' => 'etwa 30 Minuten'],
            'correct' => 'B',
            'rationale' => 'Die zweite Person sagt: etwa 20 Minuten Verspaetung.',
        ],
        [
            'id' => 'hoeren_clean_08',
            'dtz_part' => 'H2 Dialoge',
            'task_type' => 'Dialog',
            'context' => 'Kita',
            'title' => 'Elternabend',
            'audio_script' => 'A: Wann ist der Elternabend? B: Am Dienstag um 19 Uhr. A: In welchem Raum? B: Im Mehrzweckraum im Erdgeschoss.',
            'question' => 'Wo findet der Elternabend statt?',
            'options' => ['A' => 'im Sekretariat', 'B' => 'im Mehrzweckraum im Erdgeschoss', 'C' => 'in der Turnhalle'],
            'correct' => 'B',
            'rationale' => 'Der Ort wird im Dialog direkt genannt.',
        ],
        [
            'id' => 'hoeren_clean_09',
            'dtz_part' => 'H2 Dialoge',
            'task_type' => 'Dialog',
            'context' => 'Arbeit',
            'title' => 'Urlaubsantrag',
            'audio_script' => 'A: Ich moechte im August eine Woche Urlaub nehmen. B: Kein Problem, schicken Sie mir den Antrag bis Freitag per E-Mail.',
            'question' => 'Was soll A bis Freitag machen?',
            'options' => ['A' => 'eine E-Mail mit dem Antrag schicken', 'B' => 'den Urlaub absagen', 'C' => 'im Buero vorbeikommen'],
            'correct' => 'A',
            'rationale' => 'B bittet um den Antrag per E-Mail bis Freitag.',
        ],
        [
            'id' => 'hoeren_clean_10',
            'dtz_part' => 'H2 Dialoge',
            'task_type' => 'Dialog',
            'context' => 'Arzt',
            'title' => 'Rezept',
            'audio_script' => 'A: Kann ich das Rezept heute noch bekommen? B: Ja, aber erst ab 15 Uhr an der Anmeldung.',
            'question' => 'Wann kann A das Rezept abholen?',
            'options' => ['A' => 'ab 14 Uhr', 'B' => 'ab 15 Uhr', 'C' => 'ab 16 Uhr'],
            'correct' => 'B',
            'rationale' => 'Die Antwort lautet: erst ab 15 Uhr.',
        ],
        [
            'id' => 'hoeren_clean_11',
            'dtz_part' => 'H2 Dialoge',
            'task_type' => 'Dialog',
            'context' => 'Supermarkt',
            'title' => 'Reklamation',
            'audio_script' => 'A: Die Kaffeemaschine funktioniert nicht. B: Haben Sie den Kassenbon dabei? A: Ja. B: Dann koennen wir das Geraet umtauschen.',
            'question' => 'Was passiert mit der Kaffeemaschine?',
            'options' => ['A' => 'Sie wird repariert.', 'B' => 'Sie wird umgetauscht.', 'C' => 'Sie wird rabattiert verkauft.'],
            'correct' => 'B',
            'rationale' => 'Der Mitarbeiter sagt: wir koennen das Geraet umtauschen.',
        ],
        [
            'id' => 'hoeren_clean_12',
            'dtz_part' => 'H2 Dialoge',
            'task_type' => 'Dialog',
            'context' => 'Volkshochschule',
            'title' => 'Kursanmeldung',
            'audio_script' => 'A: Ist im Deutschkurs noch ein Platz frei? B: Ja, aber nur in der Gruppe am Vormittag. A: Gut, dann nehme ich diesen Kurs.',
            'question' => 'Welche Gruppe ist noch frei?',
            'options' => ['A' => 'nur die Abendgruppe', 'B' => 'nur die Vormittagsgruppe', 'C' => 'beide Gruppen'],
            'correct' => 'B',
            'rationale' => 'B sagt: nur in der Gruppe am Vormittag.',
        ],
        [
            'id' => 'hoeren_clean_13',
            'dtz_part' => 'H3 Informationsgespräche',
            'task_type' => 'Informationsgespräch',
            'context' => 'Bank',
            'title' => 'Kontooeffnung',
            'audio_script' => 'Fuer die Kontooeffnung brauchen Sie einen Ausweis und eine Meldebescheinigung. Die Kontofuehrung kostet 4 Euro pro Monat. Terminvereinbarung ist online moeglich.',
            'question' => 'Wie hoch sind die monatlichen Kosten?',
            'options' => ['A' => '3 Euro', 'B' => '4 Euro', 'C' => '5 Euro'],
            'correct' => 'B',
            'rationale' => 'Im Text steht: Die Kontofuehrung kostet 4 Euro pro Monat.',
        ],
        [
            'id' => 'hoeren_clean_14',
            'dtz_part' => 'H3 Informationsgespräche',
            'task_type' => 'Informationsgespräch',
            'context' => 'Bibliothek',
            'title' => 'Ausweis verlaengern',
            'audio_script' => 'Die Verlaengerung des Bibliotheksausweises ist an der Information moeglich. Bitte bringen Sie Ihren Ausweis und 10 Euro Jahresgebuehr mit.',
            'question' => 'Wo kann man den Ausweis verlaengern?',
            'options' => ['A' => 'am Automaten', 'B' => 'an der Information', 'C' => 'im Lesesaal'],
            'correct' => 'B',
            'rationale' => 'Genannt wird: an der Information.',
        ],
        [
            'id' => 'hoeren_clean_15',
            'dtz_part' => 'H3 Informationsgespräche',
            'task_type' => 'Informationsgespräch',
            'context' => 'Fahrschule',
            'title' => 'Praxisstunden',
            'audio_script' => 'Praxisstunden finden montags und donnerstags statt. Eine Stunde dauert 90 Minuten. Treffpunkt ist vor der Fahrschule.',
            'question' => 'Wie lange dauert eine Praxisstunde?',
            'options' => ['A' => '60 Minuten', 'B' => '75 Minuten', 'C' => '90 Minuten'],
            'correct' => 'C',
            'rationale' => 'Im Hoertext steht: Eine Stunde dauert 90 Minuten.',
        ],
        [
            'id' => 'hoeren_clean_16',
            'dtz_part' => 'H3 Informationsgespräche',
            'task_type' => 'Informationsgespräch',
            'context' => 'Jobcenter',
            'title' => 'Unterlagen nachreichen',
            'audio_script' => 'Bitte senden Sie die fehlenden Unterlagen bis zum 18. Mai per Upload-Portal. Wenn etwas fehlt, koennen wir den Antrag nicht bearbeiten.',
            'question' => 'Bis wann sollen die Unterlagen geschickt werden?',
            'options' => ['A' => 'bis 8. Mai', 'B' => 'bis 18. Mai', 'C' => 'bis 28. Mai'],
            'correct' => 'B',
            'rationale' => 'Der Termin ist eindeutig: bis zum 18. Mai.',
        ],
        [
            'id' => 'hoeren_clean_17',
            'dtz_part' => 'H3 Informationsgespräche',
            'task_type' => 'Informationsgespräch',
            'context' => 'Wohnung',
            'title' => 'Hausordnung',
            'audio_script' => 'Im Haus ist Ruhezeit von 22 Uhr bis 7 Uhr. Bitte stellen Sie Fahrraeder nur im Keller ab und nicht im Treppenhaus.',
            'question' => 'Wo sollen Fahrraeder abgestellt werden?',
            'options' => ['A' => 'im Treppenhaus', 'B' => 'im Hinterhof', 'C' => 'im Keller'],
            'correct' => 'C',
            'rationale' => 'Die Ansage sagt: Fahrraeder nur im Keller abstellen.',
        ],
        [
            'id' => 'hoeren_clean_18',
            'dtz_part' => 'H3 Informationsgespräche',
            'task_type' => 'Informationsgespräch',
            'context' => 'Krankenkasse',
            'title' => 'Karte erneuern',
            'audio_script' => 'Ihre Gesundheitskarte ist abgelaufen. Bitte laden Sie ein aktuelles Passfoto hoch. Danach bekommen Sie die neue Karte in zwei Wochen per Post.',
            'question' => 'Wann kommt die neue Karte?',
            'options' => ['A' => 'in einer Woche', 'B' => 'in zwei Wochen', 'C' => 'in drei Wochen'],
            'correct' => 'B',
            'rationale' => 'Die Lieferzeit wird mit zwei Wochen angegeben.',
        ],
        [
            'id' => 'hoeren_clean_19',
            'dtz_part' => 'H4 Meinungen/Absichten',
            'task_type' => 'Meinung',
            'context' => 'Freizeit',
            'title' => 'Sportverein',
            'audio_script' => 'Ich moechte wieder regelmaessig Sport machen. Deshalb melde ich mich im Schwimmverein an. Dort trainiere ich zweimal pro Woche am Abend.',
            'question' => 'Warum meldet sich die Person im Verein an?',
            'options' => ['A' => 'weil sie umziehen will', 'B' => 'weil sie regelmaessig Sport machen moechte', 'C' => 'weil sie einen neuen Job sucht'],
            'correct' => 'B',
            'rationale' => 'Der Grund wird direkt genannt: wieder regelmaessig Sport machen.',
        ],
        [
            'id' => 'hoeren_clean_20',
            'dtz_part' => 'H4 Meinungen/Absichten',
            'task_type' => 'Absicht',
            'context' => 'Beruf',
            'title' => 'Weiterbildung',
            'audio_script' => 'Naechsten Monat beginne ich einen Computerkurs. Ich brauche bessere digitale Kenntnisse fuer meine Arbeit im Buero.',
            'question' => 'Wozu macht die Person den Computerkurs?',
            'options' => ['A' => 'fuer den Fuehrerschein', 'B' => 'fuer den Urlaub', 'C' => 'fuer bessere Kenntnisse im Job'],
            'correct' => 'C',
            'rationale' => 'Im Text steht: fuer die Arbeit im Buero.',
        ],
        [
            'id' => 'hoeren_clean_21',
            'dtz_part' => 'H4 Meinungen/Absichten',
            'task_type' => 'Meinung',
            'context' => 'Schule',
            'title' => 'Elternabend',
            'audio_script' => 'Ich finde Elternabende wichtig, weil ich dort direkt mit den Lehrern sprechen kann. So weiss ich besser, wie es meinem Kind in der Schule geht.',
            'question' => 'Warum findet die Person Elternabende wichtig?',
            'options' => ['A' => 'Sie kann dort direkt mit Lehrern sprechen.', 'B' => 'Dort gibt es kostenloses Essen.', 'C' => 'Dort bekommt man neue Schulbuecher.'],
            'correct' => 'A',
            'rationale' => 'Die Person begruendet es mit dem direkten Gespraech mit Lehrern.',
        ],
        [
            'id' => 'hoeren_clean_22',
            'dtz_part' => 'H4 Meinungen/Absichten',
            'task_type' => 'Absicht',
            'context' => 'Wohnen',
            'title' => 'Umzug',
            'audio_script' => 'Wir suchen eine groessere Wohnung, weil unser zweites Kind bald geboren wird. Wichtig sind fuer uns ein Aufzug und ein Spielplatz in der Naehe.',
            'question' => 'Was ist der Grund fuer die Wohnungssuche?',
            'options' => ['A' => 'Ein neues Haustier', 'B' => 'Bald kommt ein zweites Kind', 'C' => 'Die Miete wird halbiert'],
            'correct' => 'B',
            'rationale' => 'Die Geburt des zweiten Kindes wird als Grund genannt.',
        ],
        [
            'id' => 'hoeren_clean_23',
            'dtz_part' => 'H4 Meinungen/Absichten',
            'task_type' => 'Meinung',
            'context' => 'Verkehr',
            'title' => 'Arbeitsweg',
            'audio_script' => 'Ich fahre lieber mit dem Fahrrad zur Arbeit. Das ist guenstig und ich bin morgens gleich aktiv. Nur bei starkem Regen nehme ich den Bus.',
            'question' => 'Wann nimmt die Person den Bus?',
            'options' => ['A' => 'bei starkem Regen', 'B' => 'jeden Morgen', 'C' => 'nur am Wochenende'],
            'correct' => 'A',
            'rationale' => 'Die Ausnahme wird klar genannt: nur bei starkem Regen.',
        ],
        [
            'id' => 'hoeren_clean_24',
            'dtz_part' => 'H4 Meinungen/Absichten',
            'task_type' => 'Absicht',
            'context' => 'Kurs',
            'title' => 'Pruefungsvorbereitung',
            'audio_script' => 'Ich moechte die B1-Pruefung im Juni bestehen. Deshalb lerne ich jeden Tag 30 Minuten Wortschatz und schreibe zweimal pro Woche einen kurzen Text.',
            'question' => 'Was macht die Person fuer die Vorbereitung?',
            'options' => ['A' => 'Sie lernt taeglich Wortschatz und schreibt regelmaessig Texte.', 'B' => 'Sie macht nur am Wochenende Uebungen.', 'C' => 'Sie pausiert bis Mai mit dem Lernen.'],
            'correct' => 'A',
            'rationale' => 'Im Text werden taeglicher Wortschatz und zwei Texte pro Woche genannt.',
        ],
        [
            'id' => 'hoeren_clean_25',
            'dtz_part' => 'H1 Kurze Ansagen',
            'task_type' => 'Telefonansage',
            'context' => 'Zahnarzt',
            'title' => 'Kontrolltermin',
            'audio_script' => 'Guten Tag, hier ist die Zahnarztpraxis Berg. Ihr Kontrolltermin ist am Montag um 8:40 Uhr. Bitte kommen Sie zehn Minuten frueher.',
            'question' => 'Wann ist der Termin?',
            'options' => ['A' => 'am Montag um 8:40 Uhr', 'B' => 'am Montag um 9:40 Uhr', 'C' => 'am Dienstag um 8:40 Uhr'],
            'correct' => 'A',
            'rationale' => 'Termin: Montag, 8:40 Uhr.',
        ],
        [
            'id' => 'hoeren_clean_26',
            'dtz_part' => 'H2 Dialoge',
            'task_type' => 'Dialog',
            'context' => 'Paketzustellung',
            'title' => 'Abholschein',
            'audio_script' => 'A: Haben Sie ein Paket fuer mich? B: Ja, es liegt in Filiale 12. A: Bis wann kann ich es abholen? B: Bis Samstag, 13 Uhr.',
            'question' => 'Bis wann kann das Paket abgeholt werden?',
            'options' => ['A' => 'bis Freitag, 13 Uhr', 'B' => 'bis Samstag, 13 Uhr', 'C' => 'bis Samstag, 15 Uhr'],
            'correct' => 'B',
            'rationale' => 'Im Dialog wird Samstag, 13 Uhr genannt.',
        ],
        [
            'id' => 'hoeren_clean_27',
            'dtz_part' => 'H3 Informationsgespräche',
            'task_type' => 'Informationsgespräch',
            'context' => 'Energieversorger',
            'title' => 'Zählerstand',
            'audio_script' => 'Bitte melden Sie den Zaehlerstand bis zum Monatsende im Online-Portal. Ohne Zaehlerstand bekommen Sie eine Schaetzung statt einer genauen Rechnung.',
            'question' => 'Was passiert ohne Zaehlerstand?',
            'options' => ['A' => 'Es gibt keine Rechnung.', 'B' => 'Die Rechnung wird geschaetzt.', 'C' => 'Der Vertrag endet sofort.'],
            'correct' => 'B',
            'rationale' => 'Ohne Zaehlerstand wird eine Schaetzung erstellt.',
        ],
        [
            'id' => 'hoeren_clean_28',
            'dtz_part' => 'H4 Meinungen/Absichten',
            'task_type' => 'Meinung',
            'context' => 'Ernährung',
            'title' => 'Gesunde Pause',
            'audio_script' => 'In der Mittagspause esse ich lieber etwas Leichtes, zum Beispiel Salat und Obst. Danach kann ich mich im Kurs besser konzentrieren.',
            'question' => 'Warum isst die Person leicht zu Mittag?',
            'options' => ['A' => 'Damit sie sich besser konzentrieren kann.', 'B' => 'Damit sie schneller nach Hause fahren kann.', 'C' => 'Damit sie Geld fuer den Urlaub spart.'],
            'correct' => 'A',
            'rationale' => 'Der Grund ist bessere Konzentration im Kurs.',
        ],
        [
            'id' => 'hoeren_clean_29',
            'dtz_part' => 'H2 Dialoge',
            'task_type' => 'Dialog',
            'context' => 'Schule',
            'title' => 'Krankmeldung Kind',
            'audio_script' => 'A: Mein Sohn ist krank und kann heute nicht in die Schule kommen. B: Kein Problem. Schicken Sie bitte bis 10 Uhr eine kurze E-Mail an das Sekretariat.',
            'question' => 'Was soll A machen?',
            'options' => ['A' => 'bis 10 Uhr eine E-Mail ans Sekretariat schicken', 'B' => 'in die Schule kommen und warten', 'C' => 'morgen im Sekretariat anrufen'],
            'correct' => 'A',
            'rationale' => 'Gefordert ist eine E-Mail bis 10 Uhr.',
        ],
        [
            'id' => 'hoeren_clean_30',
            'dtz_part' => 'H3 Informationsgespräche',
            'task_type' => 'Informationsgespräch',
            'context' => 'Rathaus',
            'title' => 'Meldebescheinigung',
            'audio_script' => 'Eine Meldebescheinigung kostet 10 Euro und wird sofort am Schalter ausgestellt. Kartenzahlung ist moeglich.',
            'question' => 'Wie viel kostet die Meldebescheinigung?',
            'options' => ['A' => '8 Euro', 'B' => '10 Euro', 'C' => '12 Euro'],
            'correct' => 'B',
            'rationale' => 'Im Informationstext steht: 10 Euro.',
        ],
        [
            'id' => 'hoeren_clean_31',
            'dtz_part' => 'H1 Kurze Ansagen',
            'task_type' => 'Kursansage',
            'context' => 'Sprachschule',
            'title' => 'Unterricht online',
            'audio_script' => 'Achtung, wegen starkem Schneefall findet der Abendkurs heute online statt. Start ist wie immer um 18 Uhr. Den Link finden Sie im E-Mail-Postfach.',
            'question' => 'Wo findet der Kurs heute statt?',
            'options' => ['A' => 'im Raum 2', 'B' => 'online', 'C' => 'im Stadtteilzentrum'],
            'correct' => 'B',
            'rationale' => 'Die Ansage sagt: heute online.',
        ],
        [
            'id' => 'hoeren_clean_32',
            'dtz_part' => 'H1 Kurze Ansagen',
            'task_type' => 'Verkehrsansage',
            'context' => 'Bus',
            'title' => 'Haltestelle entfällt',
            'audio_script' => 'Hinweis fuer Fahrgaeste der Linie 52: Wegen einer Baustelle entfaellt heute die Haltestelle Markt. Bitte steigen Sie an der Haltestelle Rathaus aus.',
            'question' => 'Welche Haltestelle entfällt?',
            'options' => ['A' => 'Rathaus', 'B' => 'Bahnhof', 'C' => 'Markt'],
            'correct' => 'C',
            'rationale' => 'Im Text steht: die Haltestelle Markt entfaellt.',
        ],
        [
            'id' => 'hoeren_clean_33',
            'dtz_part' => 'H2 Dialoge',
            'task_type' => 'Dialog',
            'context' => 'Wohnung',
            'title' => 'Vertragsunterschrift',
            'audio_script' => 'A: Wann kann ich den Mietvertrag unterschreiben? B: Am Montag um 16 Uhr im Buero der Hausverwaltung. A: Soll ich Unterlagen mitbringen? B: Ja, bitte Ausweis und Gehaltsnachweise.',
            'question' => 'Welche Unterlagen soll A mitbringen?',
            'options' => ['A' => 'Ausweis und Gehaltsnachweise', 'B' => 'nur den Ausweis', 'C' => 'nur Kontoauszuege'],
            'correct' => 'A',
            'rationale' => 'B nennt zwei Unterlagen: Ausweis und Gehaltsnachweise.',
        ],
        [
            'id' => 'hoeren_clean_34',
            'dtz_part' => 'H2 Dialoge',
            'task_type' => 'Dialog',
            'context' => 'Arzt',
            'title' => 'Blutuntersuchung',
            'audio_script' => 'A: Ich habe morgen eine Blutuntersuchung. Darf ich vorher fruehstuecken? B: Nein, bitte kommen Sie nuechtern. Wasser duerfen Sie trinken.',
            'question' => 'Was darf A vor dem Termin?',
            'options' => ['A' => 'normal fruehstuecken', 'B' => 'nur Wasser trinken', 'C' => 'Kaffee mit Milch trinken'],
            'correct' => 'B',
            'rationale' => 'Im Dialog steht: nuechtern kommen, Wasser ist erlaubt.',
        ],
        [
            'id' => 'hoeren_clean_35',
            'dtz_part' => 'H3 Informationsgespräche',
            'task_type' => 'Informationsgespräch',
            'context' => 'Bibliothek',
            'title' => 'Sprachcafé',
            'audio_script' => 'Das Sprachcafe der Stadtbibliothek findet jeden Donnerstag von 17 bis 18:30 Uhr statt. Die Teilnahme ist kostenlos, eine Anmeldung ist nicht noetig.',
            'question' => 'Was kostet die Teilnahme am Sprachcafé?',
            'options' => ['A' => '5 Euro', 'B' => '10 Euro', 'C' => 'nichts'],
            'correct' => 'C',
            'rationale' => 'Im Text steht: Die Teilnahme ist kostenlos.',
        ],
        [
            'id' => 'hoeren_clean_36',
            'dtz_part' => 'H3 Informationsgespräche',
            'task_type' => 'Informationsgespräch',
            'context' => 'Stadtservice',
            'title' => 'Müllabfuhr',
            'audio_script' => 'Wegen des Feiertags verschiebt sich die Muellabfuhr diese Woche um einen Tag. Die Biotonne wird nicht am Mittwoch, sondern am Donnerstag geleert.',
            'question' => 'Wann wird die Biotonne geleert?',
            'options' => ['A' => 'am Mittwoch', 'B' => 'am Donnerstag', 'C' => 'am Freitag'],
            'correct' => 'B',
            'rationale' => 'Die Ansage nennt den Donnerstag als neuen Termin.',
        ],
        [
            'id' => 'hoeren_clean_37',
            'dtz_part' => 'H4 Meinungen/Absichten',
            'task_type' => 'Meinung',
            'context' => 'Familie',
            'title' => 'Kinderbetreuung',
            'audio_script' => 'Fuer unsere Familie ist eine flexible Kinderbetreuung wichtig, weil ich im Schichtdienst arbeite. Wenn mein Dienst wechselt, brauchen wir andere Abholzeiten.',
            'question' => 'Warum braucht die Familie flexible Betreuung?',
            'options' => ['A' => 'weil die Wohnung zu klein ist', 'B' => 'weil Schichtdienst vorliegt', 'C' => 'weil die Kita zu teuer ist'],
            'correct' => 'B',
            'rationale' => 'Der Grund wird direkt mit Schichtdienst genannt.',
        ],
        [
            'id' => 'hoeren_clean_38',
            'dtz_part' => 'H4 Meinungen/Absichten',
            'task_type' => 'Absicht',
            'context' => 'Prüfung',
            'title' => 'Führerschein Theorie',
            'audio_script' => 'Nächsten Monat mache ich die Theoriepruefung fuer den Fuehrerschein. Deshalb lerne ich jeden Abend mit der App und wiederhole am Wochenende die schwierigen Fragen.',
            'question' => 'Wie bereitet sich die Person vor?',
            'options' => ['A' => 'mit einer Lern-App am Abend', 'B' => 'nur im Unterricht am Samstag', 'C' => 'gar nicht regelmaessig'],
            'correct' => 'A',
            'rationale' => 'Die Person lernt jeden Abend mit der App.',
        ],
        [
            'id' => 'hoeren_clean_39',
            'dtz_part' => 'H2 Dialoge',
            'task_type' => 'Dialog',
            'context' => 'Bank',
            'title' => 'Karte gesperrt',
            'audio_script' => 'A: Meine Bankkarte ist weg. Was soll ich tun? B: Rufen Sie sofort die Sperrhotline an. A: Ist die Nummer auf der Webseite? B: Ja, dort finden Sie sie direkt oben.',
            'question' => 'Was ist der erste Schritt?',
            'options' => ['A' => 'neue Karte in der Filiale bestellen', 'B' => 'Sperrhotline sofort anrufen', 'C' => 'zwei Tage warten'],
            'correct' => 'B',
            'rationale' => 'B sagt klar: sofort die Sperrhotline anrufen.',
        ],
        [
            'id' => 'hoeren_clean_40',
            'dtz_part' => 'H3 Informationsgespräche',
            'task_type' => 'Informationsgespräch',
            'context' => 'Volkshochschule',
            'title' => 'Prüfungsgebühr',
            'audio_script' => 'Die Pruefungsgebuehr fuer den B1-Test betraegt 130 Euro. Bitte ueberweisen Sie den Betrag bis spaetestens 15. Juni, sonst koennen wir Sie nicht anmelden.',
            'question' => 'Was passiert bei zu später Zahlung?',
            'options' => ['A' => 'Die Anmeldung ist dann nicht moeglich.', 'B' => 'Die Gebuehr wird halbiert.', 'C' => 'Der Testtermin wird automatisch verschoben.'],
            'correct' => 'A',
            'rationale' => 'Ohne rechtzeitige Zahlung ist keine Anmeldung moeglich.',
        ],
    ];

    $templates = [];
    foreach ($entries as $entry) {
        $templates[] = [
            'id' => (string)$entry['id'],
            'module' => 'hoeren',
            'set_name' => 'DTZ Hoeren Clean',
            'dtz_part' => (string)$entry['dtz_part'],
            'task_type' => (string)$entry['task_type'],
            'context' => (string)$entry['context'],
            'title' => (string)$entry['title'],
            'instructions' => 'Hören Sie den Text und wählen Sie die richtige Antwort (A/B/C).',
            'sample_item' => [
                'audio_script' => (string)$entry['audio_script'],
                'text' => (string)$entry['audio_script'],
                'question' => (string)$entry['question'],
                'options' => [
                    'A' => (string)$entry['options']['A'],
                    'B' => (string)$entry['options']['B'],
                    'C' => (string)$entry['options']['C'],
                ],
                'correct' => (string)$entry['correct'],
                'rationale' => (string)$entry['rationale'],
            ],
        ];
    }

    return $templates;
}

function build_clean_lesen_templates(): array
{
    $entries = [
        [
            'id' => 'lesen_clean_01',
            'dtz_part' => 'L1 Kurznachrichten',
            'task_type' => 'SMS',
            'context' => 'Arztpraxis',
            'title' => 'Termin absagen',
            'text' => 'Hallo Frau Yilmaz, ich kann morgen um 9:00 Uhr nicht kommen. Ich habe einen wichtigen Arzttermin. Können wir den Termin auf Donnerstag verschieben?',
            'question' => 'Warum kann die Person morgen nicht kommen?',
            'options' => ['A' => 'Sie hat Spätschicht.', 'B' => 'Sie hat einen Arzttermin.', 'C' => 'Sie fährt in den Urlaub.'],
            'correct' => 'B',
            'rationale' => 'Im Text steht direkt: wichtiger Arzttermin.',
        ],
        [
            'id' => 'lesen_clean_02',
            'dtz_part' => 'L1 Kurznachrichten',
            'task_type' => 'WhatsApp',
            'context' => 'Sprachkurs',
            'title' => 'Raumänderung',
            'text' => 'Achtung! Der Deutschkurs ist heute in Raum 4, nicht in Raum 2. Beginn bleibt um 18:00 Uhr.',
            'question' => 'Was hat sich geändert?',
            'options' => ['A' => 'Die Uhrzeit', 'B' => 'Der Lehrer', 'C' => 'Der Kursraum'],
            'correct' => 'C',
            'rationale' => 'Die Zeit bleibt gleich, nur der Raum ist anders.',
        ],
        [
            'id' => 'lesen_clean_03',
            'dtz_part' => 'L1 Kurznachrichten',
            'task_type' => 'Notiz',
            'context' => 'Arbeit',
            'title' => 'Schichttausch',
            'text' => 'Kannst du am Freitag meine Schicht von 14 bis 22 Uhr übernehmen? Ich bringe dir dafür am Montag Kaffee mit.',
            'question' => 'Welche Schicht soll übernommen werden?',
            'options' => ['A' => '14 bis 22 Uhr', 'B' => '6 bis 14 Uhr', 'C' => '22 bis 6 Uhr'],
            'correct' => 'A',
            'rationale' => 'Genannt wird ausdrücklich 14 bis 22 Uhr.',
        ],
        [
            'id' => 'lesen_clean_04',
            'dtz_part' => 'L1 Kurznachrichten',
            'task_type' => 'E-Mail',
            'context' => 'Kita',
            'title' => 'Krankmeldung',
            'text' => 'Sehr geehrte Frau Becker, mein Sohn ist heute krank und bleibt zu Hause. Bitte schicken Sie die Hausaufgaben per E-Mail.',
            'question' => 'Was bittet die Person?',
            'options' => ['A' => 'Um einen Rückruf', 'B' => 'Um die Hausaufgaben per E-Mail', 'C' => 'Um eine Klassenfahrt'],
            'correct' => 'B',
            'rationale' => 'Die Bitte wird am Ende klar formuliert.',
        ],
        [
            'id' => 'lesen_clean_05',
            'dtz_part' => 'L1 Kurznachrichten',
            'task_type' => 'Aushang',
            'context' => 'Bibliothek',
            'title' => 'Neue Öffnungszeit',
            'text' => 'Ab nächster Woche öffnet die Bibliothek samstags erst um 10:00 Uhr. Werktags bleibt alles wie bisher.',
            'question' => 'Wann öffnet die Bibliothek am Samstag?',
            'options' => ['A' => 'Um 9:00 Uhr', 'B' => 'Um 10:00 Uhr', 'C' => 'Um 11:00 Uhr'],
            'correct' => 'B',
            'rationale' => 'Der Aushang nennt samstags 10:00 Uhr.',
        ],
        [
            'id' => 'lesen_clean_06',
            'dtz_part' => 'L2 Informationsanzeigen',
            'task_type' => 'Kleinanzeige',
            'context' => 'Wohnung',
            'title' => 'Wohnungsanzeige',
            'text' => '2-Zimmer-Wohnung, 58 m², Nähe Bahnhof. Warmmiete 820 Euro. Frei ab 1. Mai. Besichtigung nur mit Termin.',
            'question' => 'Wann ist die Wohnung frei?',
            'options' => ['A' => 'Ab 1. Mai', 'B' => 'Ab sofort', 'C' => 'Ab 1. Juni'],
            'correct' => 'A',
            'rationale' => 'In der Anzeige steht: frei ab 1. Mai.',
        ],
        [
            'id' => 'lesen_clean_07',
            'dtz_part' => 'L2 Informationsanzeigen',
            'task_type' => 'Stellenanzeige',
            'context' => 'Beruf',
            'title' => 'Minijob im Café',
            'text' => 'Café am Markt sucht Servicekraft (m/w/d) für Samstag und Sonntag. Erfahrung ist gut, aber nicht notwendig.',
            'question' => 'An welchen Tagen wird gearbeitet?',
            'options' => ['A' => 'Montag und Dienstag', 'B' => 'Samstag und Sonntag', 'C' => 'Nur Freitag'],
            'correct' => 'B',
            'rationale' => 'Die Anzeige nennt Wochenende: Samstag und Sonntag.',
        ],
        [
            'id' => 'lesen_clean_08',
            'dtz_part' => 'L2 Informationsanzeigen',
            'task_type' => 'Fahrplan',
            'context' => 'Bus',
            'title' => 'Linie 24',
            'text' => 'Linie 24 Richtung Zentrum: 07:10, 07:30, 07:50. Ab 8:00 Uhr fährt der Bus alle 20 Minuten.',
            'question' => 'Wann fährt der Bus vor 8:00 Uhr zuletzt?',
            'options' => ['A' => '07:30', 'B' => '07:50', 'C' => '08:00'],
            'correct' => 'B',
            'rationale' => 'Vor 8:00 Uhr ist der letzte genannte Bus 07:50.',
        ],
        [
            'id' => 'lesen_clean_09',
            'dtz_part' => 'L2 Informationsanzeigen',
            'task_type' => 'Supermarkt-Hinweis',
            'context' => 'Einkauf',
            'title' => 'Kasse außer Betrieb',
            'text' => 'Heute ist Kasse 3 wegen Reparatur geschlossen. Bitte benutzen Sie Kasse 1 oder Kasse 2.',
            'question' => 'Welche Kasse ist geschlossen?',
            'options' => ['A' => 'Kasse 1', 'B' => 'Kasse 2', 'C' => 'Kasse 3'],
            'correct' => 'C',
            'rationale' => 'Im Hinweis steht: Kasse 3 geschlossen.',
        ],
        [
            'id' => 'lesen_clean_10',
            'dtz_part' => 'L2 Informationsanzeigen',
            'task_type' => 'Aushang',
            'context' => 'Schule',
            'title' => 'Elternabend',
            'text' => 'Elternabend Klasse 5b am Dienstag um 19:00 Uhr in Raum B12. Bitte Zeugnisheft mitbringen.',
            'question' => 'Was sollen Eltern mitbringen?',
            'options' => ['A' => 'Ein Foto', 'B' => 'Das Zeugnisheft', 'C' => 'Einen Kuchen'],
            'correct' => 'B',
            'rationale' => 'Der Aushang verlangt das Zeugnisheft.',
        ],
        [
            'id' => 'lesen_clean_11',
            'dtz_part' => 'L3 Formulare und Hinweise',
            'task_type' => 'Behördenhinweis',
            'context' => 'Bürgeramt',
            'title' => 'Meldebescheinigung',
            'text' => 'Für die Meldebescheinigung brauchen Sie: Ausweis, 10 Euro Gebühr, Terminbestätigung.',
            'question' => 'Was ist nötig?',
            'options' => ['A' => 'Ausweis und Terminbestätigung', 'B' => 'Nur ein Passfoto', 'C' => 'Nur Bargeld'],
            'correct' => 'A',
            'rationale' => 'A enthält zwei Pflichtteile aus der Liste.',
        ],
        [
            'id' => 'lesen_clean_12',
            'dtz_part' => 'L3 Formulare und Hinweise',
            'task_type' => 'Portalhinweis',
            'context' => 'Jobcenter',
            'title' => 'Uploadfrist',
            'text' => 'Bitte laden Sie fehlende Unterlagen bis 18.05. im Portal hoch. Danach kann Ihr Antrag nicht bearbeitet werden.',
            'question' => 'Was passiert nach dem 18.05.?',
            'options' => ['A' => 'Der Antrag wird sofort bewilligt.', 'B' => 'Der Antrag kann nicht bearbeitet werden.', 'C' => 'Die Frist beginnt erst dann.'],
            'correct' => 'B',
            'rationale' => 'Das wird direkt im zweiten Satz erklärt.',
        ],
        [
            'id' => 'lesen_clean_13',
            'dtz_part' => 'L3 Formulare und Hinweise',
            'task_type' => 'Hausordnung',
            'context' => 'Wohnen',
            'title' => 'Ruhezeit',
            'text' => 'Ruhezeit im Haus: 22:00 bis 07:00 Uhr. In dieser Zeit bitte keine laute Musik und kein Bohren.',
            'question' => 'Was ist nachts nicht erlaubt?',
            'options' => ['A' => 'Fenster öffnen', 'B' => 'Laute Musik', 'C' => 'Licht anmachen'],
            'correct' => 'B',
            'rationale' => 'Laute Musik ist ausdrücklich verboten.',
        ],
        [
            'id' => 'lesen_clean_14',
            'dtz_part' => 'L3 Formulare und Hinweise',
            'task_type' => 'Kasseninformation',
            'context' => 'Krankenkasse',
            'title' => 'Neue Gesundheitskarte',
            'text' => 'Bitte senden Sie ein aktuelles Passfoto. Ihre neue Gesundheitskarte kommt dann in etwa zwei Wochen per Post.',
            'question' => 'Wann kommt die Karte?',
            'options' => ['A' => 'In etwa zwei Wochen', 'B' => 'Am nächsten Tag', 'C' => 'Erst in zwei Monaten'],
            'correct' => 'A',
            'rationale' => 'Die Dauer wird mit zwei Wochen angegeben.',
        ],
        [
            'id' => 'lesen_clean_15',
            'dtz_part' => 'L3 Formulare und Hinweise',
            'task_type' => 'Bankinformation',
            'context' => 'Konto',
            'title' => 'Kontoführung',
            'text' => 'Die Kontoführung kostet 4 Euro pro Monat. Kartenzahlung und Online-Banking sind inklusive.',
            'question' => 'Wie hoch sind die monatlichen Kosten?',
            'options' => ['A' => '3 Euro', 'B' => '4 Euro', 'C' => '5 Euro'],
            'correct' => 'B',
            'rationale' => 'Im Text steht klar: 4 Euro pro Monat.',
        ],
        [
            'id' => 'lesen_clean_16',
            'dtz_part' => 'L4 Meinungen und Absichten',
            'task_type' => 'Forum',
            'context' => 'Freizeit',
            'title' => 'Sportverein',
            'text' => 'Ich möchte wieder regelmäßig Sport machen. Deshalb habe ich mich in einem Schwimmverein angemeldet.',
            'question' => 'Warum meldet sich die Person an?',
            'options' => ['A' => 'Für einen neuen Job', 'B' => 'Für mehr Sport im Alltag', 'C' => 'Wegen einer Reise'],
            'correct' => 'B',
            'rationale' => 'Der Grund wird mit regelmäßigem Sport genannt.',
        ],
        [
            'id' => 'lesen_clean_17',
            'dtz_part' => 'L4 Meinungen und Absichten',
            'task_type' => 'Erfahrungsbericht',
            'context' => 'Kurs',
            'title' => 'Lernplan',
            'text' => 'Für die Prüfung lerne ich jeden Tag 30 Minuten Wortschatz und schreibe zweimal pro Woche einen kurzen Text.',
            'question' => 'Wie bereitet sich die Person vor?',
            'options' => ['A' => 'Nur am Wochenende', 'B' => 'Täglich Wortschatz und regelmäßig Schreiben', 'C' => 'Nur mit Videos'],
            'correct' => 'B',
            'rationale' => 'Das entspricht exakt der beschriebenen Strategie.',
        ],
        [
            'id' => 'lesen_clean_18',
            'dtz_part' => 'L4 Meinungen und Absichten',
            'task_type' => 'Kommentar',
            'context' => 'Schule',
            'title' => 'Elternabend',
            'text' => 'Ich finde Elternabende wichtig, weil man dort direkt mit den Lehrkräften sprechen kann.',
            'question' => 'Was ist der Hauptgrund?',
            'options' => ['A' => 'Direktes Gespräch mit Lehrkräften', 'B' => 'Kostenlose Getränke', 'C' => 'Neue Bücher bekommen'],
            'correct' => 'A',
            'rationale' => 'Der Text nennt nur diesen Grund.',
        ],
        [
            'id' => 'lesen_clean_19',
            'dtz_part' => 'L4 Meinungen und Absichten',
            'task_type' => 'Meinung',
            'context' => 'Arbeitsweg',
            'title' => 'Fahrrad oder Bus',
            'text' => 'Ich fahre meistens mit dem Fahrrad zur Arbeit. Nur bei starkem Regen nehme ich den Bus.',
            'question' => 'Wann nimmt die Person den Bus?',
            'options' => ['A' => 'Jeden Morgen', 'B' => 'Bei starkem Regen', 'C' => 'Nur im Winter'],
            'correct' => 'B',
            'rationale' => 'Die Ausnahme wird klar genannt.',
        ],
        [
            'id' => 'lesen_clean_20',
            'dtz_part' => 'L4 Meinungen und Absichten',
            'task_type' => 'Absicht',
            'context' => 'Weiterbildung',
            'title' => 'Computerkurs',
            'text' => 'Nächsten Monat beginne ich einen Computerkurs, weil ich in meinem Job bessere digitale Kenntnisse brauche.',
            'question' => 'Wozu macht die Person den Kurs?',
            'options' => ['A' => 'Für den Führerschein', 'B' => 'Für bessere Chancen im Job', 'C' => 'Für eine Urlaubsreise'],
            'correct' => 'B',
            'rationale' => 'Ziel: bessere digitale Kenntnisse im Beruf.',
        ],
        [
            'id' => 'lesen_clean_21',
            'dtz_part' => 'L5 Längere Texte',
            'task_type' => 'E-Mail',
            'context' => 'Wohnung',
            'title' => 'Heizung defekt',
            'text' => 'Sehr geehrte Hausverwaltung, seit gestern ist die Heizung in meiner Wohnung kalt. Bitte schicken Sie so schnell wie möglich einen Handwerker. Ich bin morgen zwischen 8 und 12 Uhr zu Hause.',
            'question' => 'Was ist das Problem?',
            'options' => ['A' => 'Die Tür ist kaputt.', 'B' => 'Die Heizung funktioniert nicht.', 'C' => 'Der Strom ist ausgefallen.'],
            'correct' => 'B',
            'rationale' => 'Die E-Mail nennt ausdrücklich eine kalte Heizung.',
        ],
        [
            'id' => 'lesen_clean_22',
            'dtz_part' => 'L5 Längere Texte',
            'task_type' => 'E-Mail',
            'context' => 'Arbeit',
            'title' => 'Urlaubsantrag',
            'text' => 'Sehr geehrte Frau Weber, ich möchte vom 10. bis 14. Juni Urlaub beantragen. In dieser Zeit übernimmt Herr Aslan meine Aufgaben. Vielen Dank für Ihre Rückmeldung.',
            'question' => 'Für welchen Zeitraum wird Urlaub beantragt?',
            'options' => ['A' => '10. bis 14. Juni', 'B' => '14. bis 20. Juni', 'C' => '1. bis 5. Juni'],
            'correct' => 'A',
            'rationale' => 'Der Zeitraum steht im zweiten Satz.',
        ],
        [
            'id' => 'lesen_clean_23',
            'dtz_part' => 'L5 Längere Texte',
            'task_type' => 'Brief',
            'context' => 'Schule',
            'title' => 'Klassenfahrt',
            'text' => 'Liebe Eltern, die Klassenfahrt findet vom 5. bis 7. Mai statt. Bitte geben Sie das unterschriebene Formular und 60 Euro bis spätestens 20. April ab.',
            'question' => 'Was sollen Eltern bis 20. April abgeben?',
            'options' => ['A' => 'Nur 60 Euro', 'B' => 'Formular und 60 Euro', 'C' => 'Nur das Formular'],
            'correct' => 'B',
            'rationale' => 'Gefordert sind beide Dinge: Formular und Geld.',
        ],
        [
            'id' => 'lesen_clean_24',
            'dtz_part' => 'L5 Längere Texte',
            'task_type' => 'Informationsmail',
            'context' => 'Verein',
            'title' => 'Mitgliedsbeitrag',
            'text' => 'Der Sportverein erhöht ab Juli den Beitrag auf 18 Euro pro Monat. Wer Fragen hat, kann am Mittwoch zwischen 17 und 19 Uhr im Büro anrufen.',
            'question' => 'Wie hoch ist der neue Beitrag?',
            'options' => ['A' => '16 Euro', 'B' => '17 Euro', 'C' => '18 Euro'],
            'correct' => 'C',
            'rationale' => 'Der neue Betrag wird direkt genannt.',
        ],
        [
            'id' => 'lesen_clean_25',
            'dtz_part' => 'L2 Informationsanzeigen',
            'task_type' => 'Ticketautomat',
            'context' => 'Bahnhof',
            'title' => 'Kartenzahlung',
            'text' => 'Der Ticketautomat am Gleis 2 akzeptiert heute nur Kartenzahlung. Barzahlung ist am Schalter möglich.',
            'question' => 'Wo kann man bar bezahlen?',
            'options' => ['A' => 'Am Automaten', 'B' => 'Am Schalter', 'C' => 'Gar nicht'],
            'correct' => 'B',
            'rationale' => 'Barzahlung ist laut Text nur am Schalter möglich.',
        ],
        [
            'id' => 'lesen_clean_26',
            'dtz_part' => 'L3 Formulare und Hinweise',
            'task_type' => 'Schulmitteilung',
            'context' => 'Unterricht',
            'title' => 'Sporttag',
            'text' => 'Am Freitag ist Sporttag. Bitte geben Sie Ihrem Kind Sportschuhe, Wasser und ein T-Shirt mit.',
            'question' => 'Was soll das Kind mitbringen?',
            'options' => ['A' => 'Sportschuhe, Wasser und T-Shirt', 'B' => 'Laptop und Heft', 'C' => 'Nur eine Jacke'],
            'correct' => 'A',
            'rationale' => 'Alle drei Dinge werden genannt.',
        ],
        [
            'id' => 'lesen_clean_27',
            'dtz_part' => 'L4 Meinungen und Absichten',
            'task_type' => 'Gesundheitstipp',
            'context' => 'Alltag',
            'title' => 'Besser schlafen',
            'text' => 'Seit ich abends keinen Kaffee mehr trinke, schlafe ich besser. Jetzt bin ich morgens im Kurs konzentrierter.',
            'question' => 'Welche Änderung hilft der Person?',
            'options' => ['A' => 'Abends kein Kaffee', 'B' => 'Später aufstehen', 'C' => 'Mehr Fernsehen'],
            'correct' => 'A',
            'rationale' => 'Die Ursache wird direkt erklärt.',
        ],
        [
            'id' => 'lesen_clean_28',
            'dtz_part' => 'L1 Kurznachrichten',
            'task_type' => 'Paketinfo',
            'context' => 'Post',
            'title' => 'Abholung',
            'text' => 'Ihr Paket liegt in der Filiale Nordstraße 12 bereit. Abholung möglich bis Samstag, 13:00 Uhr.',
            'question' => 'Bis wann kann das Paket abgeholt werden?',
            'options' => ['A' => 'Bis Samstag, 13:00 Uhr', 'B' => 'Bis Freitag, 13:00 Uhr', 'C' => 'Bis Samstag, 15:00 Uhr'],
            'correct' => 'A',
            'rationale' => 'Zeitangabe: Samstag, 13:00 Uhr.',
        ],
        [
            'id' => 'lesen_clean_29',
            'dtz_part' => 'L2 Informationsanzeigen',
            'task_type' => 'Öffnungszeiten',
            'context' => 'Apotheke',
            'title' => 'Feiertag',
            'text' => 'Am Feiertag hat die Adler-Apotheke von 10:00 bis 14:00 Uhr geöffnet. Danach übernimmt der Notdienst.',
            'question' => 'Wie lange ist die Apotheke am Feiertag geöffnet?',
            'options' => ['A' => 'Von 9:00 bis 14:00 Uhr', 'B' => 'Von 10:00 bis 14:00 Uhr', 'C' => 'Von 10:00 bis 16:00 Uhr'],
            'correct' => 'B',
            'rationale' => 'Die Öffnungszeit ist exakt angegeben.',
        ],
        [
            'id' => 'lesen_clean_30',
            'dtz_part' => 'L5 Längere Texte',
            'task_type' => 'E-Mail',
            'context' => 'Arbeit',
            'title' => 'Fortbildung',
            'text' => 'Guten Tag Herr Krause, ich möchte am 22. April an einer Fortbildung teilnehmen. Der Kurs dauert von 9 bis 16 Uhr. Können Sie mich für diesen Tag freistellen?',
            'question' => 'Was bittet die Person?',
            'options' => ['A' => 'Um Gehaltserhöhung', 'B' => 'Um Freistellung für den Kurstag', 'C' => 'Um einen Firmenwagen'],
            'correct' => 'B',
            'rationale' => 'Die Bitte um Freistellung steht im letzten Satz.',
        ],
        [
            'id' => 'lesen_clean_31',
            'dtz_part' => 'L1 Kurznachrichten',
            'task_type' => 'SMS',
            'context' => 'Arztpraxis',
            'title' => 'Blutabnahme',
            'text' => 'Bitte denken Sie an Ihren Termin zur Blutabnahme morgen um 7:30 Uhr. Wichtig: nüchtern kommen, nur Wasser trinken.',
            'question' => 'Was ist vor dem Termin erlaubt?',
            'options' => ['A' => 'Frühstück', 'B' => 'Nur Wasser', 'C' => 'Kaffee mit Milch'],
            'correct' => 'B',
            'rationale' => 'Im Text steht: nur Wasser trinken.',
        ],
        [
            'id' => 'lesen_clean_32',
            'dtz_part' => 'L2 Informationsanzeigen',
            'task_type' => 'Hinweis',
            'context' => 'Verkehr',
            'title' => 'Umleitung',
            'text' => 'Die Linie 52 fährt diese Woche wegen Bauarbeiten eine Umleitung. Die Haltestelle Markt wird nicht bedient.',
            'question' => 'Welche Haltestelle wird nicht bedient?',
            'options' => ['A' => 'Markt', 'B' => 'Rathaus', 'C' => 'Zentrum'],
            'correct' => 'A',
            'rationale' => 'Die Haltestelle Markt wird ausdrücklich genannt.',
        ],
        [
            'id' => 'lesen_clean_33',
            'dtz_part' => 'L2 Informationsanzeigen',
            'task_type' => 'Aushang',
            'context' => 'Kurs',
            'title' => 'Sprachcafé',
            'text' => 'Sprachcafé in der Bibliothek: jeden Donnerstag von 17:00 bis 18:30 Uhr. Ohne Anmeldung, kostenlos.',
            'question' => 'Was stimmt zum Sprachcafé?',
            'options' => ['A' => 'Anmeldung ist Pflicht.', 'B' => 'Es kostet 8 Euro.', 'C' => 'Es ist kostenlos und ohne Anmeldung.'],
            'correct' => 'C',
            'rationale' => 'Die Anzeige nennt beide Infos direkt.',
        ],
        [
            'id' => 'lesen_clean_34',
            'dtz_part' => 'L3 Formulare und Hinweise',
            'task_type' => 'Amtliche Information',
            'context' => 'Rathaus',
            'title' => 'Gebühr',
            'text' => 'Für eine Meldebescheinigung zahlen Sie 10 Euro. Bitte bringen Sie Ihren Ausweis zum Termin mit.',
            'question' => 'Was muss man zum Termin mitbringen?',
            'options' => ['A' => 'Den Ausweis', 'B' => 'Ein Passfoto', 'C' => 'Die Krankenkassenkarte'],
            'correct' => 'A',
            'rationale' => 'Gefordert wird der Ausweis.',
        ],
        [
            'id' => 'lesen_clean_35',
            'dtz_part' => 'L3 Formulare und Hinweise',
            'task_type' => 'Kassenbrief',
            'context' => 'Krankenkasse',
            'title' => 'Neue Karte',
            'text' => 'Ihre Gesundheitskarte läuft ab. Bitte laden Sie bis 30. April ein aktuelles Foto hoch, damit wir die neue Karte drucken können.',
            'question' => 'Bis wann soll das Foto hochgeladen werden?',
            'options' => ['A' => 'bis 20. April', 'B' => 'bis 30. April', 'C' => 'bis 30. Mai'],
            'correct' => 'B',
            'rationale' => 'Die Frist ist 30. April.',
        ],
        [
            'id' => 'lesen_clean_36',
            'dtz_part' => 'L4 Meinungen und Absichten',
            'task_type' => 'Kommentar',
            'context' => 'Familie',
            'title' => 'Kinderbetreuung',
            'text' => 'Ich brauche flexible Betreuungszeiten, weil ich im Schichtdienst arbeite und meine Arbeitszeiten jede Woche anders sind.',
            'question' => 'Warum braucht die Person flexible Zeiten?',
            'options' => ['A' => 'wegen Schichtdienst', 'B' => 'wegen Urlaub', 'C' => 'wegen Umzug'],
            'correct' => 'A',
            'rationale' => 'Der Grund wird mit Schichtdienst begründet.',
        ],
        [
            'id' => 'lesen_clean_37',
            'dtz_part' => 'L4 Meinungen und Absichten',
            'task_type' => 'Lernplan',
            'context' => 'Prüfung',
            'title' => 'Theorieprüfung',
            'text' => 'Für die Theorieprüfung lerne ich täglich mit einer App. Am Wochenende wiederhole ich die falschen Antworten aus den letzten Tests.',
            'question' => 'Wie lernt die Person?',
            'options' => ['A' => 'nur im Kursraum', 'B' => 'täglich mit App und Wiederholung am Wochenende', 'C' => 'nur mit Büchern einmal im Monat'],
            'correct' => 'B',
            'rationale' => 'Der Text beschreibt genau diesen Lernrhythmus.',
        ],
        [
            'id' => 'lesen_clean_38',
            'dtz_part' => 'L5 Längere Texte',
            'task_type' => 'E-Mail',
            'context' => 'Arbeit',
            'title' => 'Fortbildung',
            'text' => 'Sehr geehrte Frau Schneider, ich möchte am 12. Juli an einer Fortbildung teilnehmen. Der Kurs dauert von 9 bis 16 Uhr. Können Sie mich für diesen Tag freistellen?',
            'question' => 'Worum bittet die Person?',
            'options' => ['A' => 'um Überstunden', 'B' => 'um Freistellung am 12. Juli', 'C' => 'um einen neuen Arbeitsplatz'],
            'correct' => 'B',
            'rationale' => 'Die Bitte um Freistellung steht im letzten Satz.',
        ],
        [
            'id' => 'lesen_clean_39',
            'dtz_part' => 'L5 Längere Texte',
            'task_type' => 'E-Mail',
            'context' => 'Wohnung',
            'title' => 'Waschmaschine defekt',
            'text' => 'Guten Tag, seit gestern funktioniert meine Waschmaschine nicht mehr. Bitte schicken Sie einen Techniker. Ich bin werktags ab 17 Uhr erreichbar.',
            'question' => 'Seit wann besteht das Problem?',
            'options' => ['A' => 'seit gestern', 'B' => 'seit letzter Woche', 'C' => 'seit heute Morgen'],
            'correct' => 'A',
            'rationale' => 'Im Text steht: seit gestern.',
        ],
        [
            'id' => 'lesen_clean_40',
            'dtz_part' => 'L2 Informationsanzeigen',
            'task_type' => 'VHS-Info',
            'context' => 'Volkshochschule',
            'title' => 'Prüfungsgebühr',
            'text' => 'Die Gebühr für die B1-Prüfung beträgt 130 Euro. Bitte zahlen Sie bis 15. Juni per Überweisung.',
            'question' => 'Wie soll man zahlen?',
            'options' => ['A' => 'bar am Prüfungstag', 'B' => 'per Überweisung', 'C' => 'per Lastschriftformular'],
            'correct' => 'B',
            'rationale' => 'Die Zahlungsart wird klar genannt: Überweisung.',
        ],
    ];

    $templates = [];
    foreach ($entries as $entry) {
        $templates[] = [
            'id' => (string)$entry['id'],
            'module' => 'lesen',
            'set_name' => 'DTZ Lesen Clean',
            'dtz_part' => (string)$entry['dtz_part'],
            'task_type' => (string)$entry['task_type'],
            'context' => (string)$entry['context'],
            'title' => (string)$entry['title'],
            'instructions' => 'Lesen Sie den Text und wählen Sie die richtige Antwort (A/B/C).',
            'sample_item' => [
                'text' => (string)$entry['text'],
                'question' => (string)$entry['question'],
                'options' => [
                    'A' => (string)$entry['options']['A'],
                    'B' => (string)$entry['options']['B'],
                    'C' => (string)$entry['options']['C'],
                ],
                'correct' => (string)$entry['correct'],
                'rationale' => (string)$entry['rationale'],
            ],
        ];
    }

    return $templates;
}

function build_lesen_teil1_text_templates(): array
{
    $sharedText = <<<TXT
Mitteilung aus dem Kurszentrum:
Nächste Woche findet im Kurszentrum eine Informationswoche statt. Am Montag gibt es um 17:30 Uhr eine kurze Einführung für neue Teilnehmende in Raum 2. Am Dienstag bleibt das Büro wegen einer Fortbildung geschlossen, aber E-Mails werden trotzdem beantwortet. Wer eine Kursbescheinigung braucht, kann das Formular bis Donnerstag um 12:00 Uhr online senden. Am Freitag ist das Sprachcafé nicht um 18:00 Uhr, sondern schon um 17:00 Uhr im Lernraum.
TXT;

    $items = [
        [
            'id' => 'l1_text_01',
            'title' => 'Einführung für neue Teilnehmende',
            'question' => 'Wann beginnt die Einführung am Montag?',
            'options' => [
                'A' => 'um 17:00 Uhr',
                'B' => 'um 17:30 Uhr',
                'C' => 'um 18:00 Uhr',
            ],
            'correct' => 'B',
            'rationale' => 'Im Text steht: Am Montag gibt es um 17:30 Uhr eine Einführung.',
        ],
        [
            'id' => 'l1_text_02',
            'title' => 'Ort der Einführung',
            'question' => 'Wo findet die Einführung statt?',
            'options' => [
                'A' => 'im Lernraum',
                'B' => 'im Büro',
                'C' => 'in Raum 2',
            ],
            'correct' => 'C',
            'rationale' => 'Der Text nennt als Ort ausdrücklich Raum 2.',
        ],
        [
            'id' => 'l1_text_03',
            'title' => 'Büro am Dienstag',
            'question' => 'Was stimmt zum Dienstag?',
            'options' => [
                'A' => 'Das Büro ist geschlossen.',
                'B' => 'Das Büro ist bis 12:00 Uhr offen.',
                'C' => 'Das Büro öffnet erst um 17:00 Uhr.',
            ],
            'correct' => 'A',
            'rationale' => 'Dort steht: Am Dienstag bleibt das Büro geschlossen.',
        ],
        [
            'id' => 'l1_text_04',
            'title' => 'Kursbescheinigung',
            'question' => 'Bis wann kann man das Formular für die Kursbescheinigung senden?',
            'options' => [
                'A' => 'bis Mittwoch um 12:00 Uhr',
                'B' => 'bis Donnerstag um 12:00 Uhr',
                'C' => 'bis Freitag um 17:00 Uhr',
            ],
            'correct' => 'B',
            'rationale' => 'Im Text steht klar: bis Donnerstag um 12:00 Uhr.',
        ],
        [
            'id' => 'l1_text_05',
            'title' => 'Sprachcafé am Freitag',
            'question' => 'Was hat sich beim Sprachcafé geändert?',
            'options' => [
                'A' => 'Es beginnt schon um 17:00 Uhr.',
                'B' => 'Es ist am Freitag abgesagt.',
                'C' => 'Es findet im Büro statt.',
            ],
            'correct' => 'A',
            'rationale' => 'Das Sprachcafé ist nicht um 18:00 Uhr, sondern schon um 17:00 Uhr.',
        ],
    ];

    $templates = [];
    foreach ($items as $entry) {
        $templates[] = [
            'id' => (string)$entry['id'],
            'module' => 'lesen',
            'set_name' => 'DTZ Lesen Teil 1',
            'dtz_part' => 'L1 Kurznachrichten und Mitteilungen',
            'task_type' => 'Textverständnis',
            'context' => 'Situationen 1-5',
            'title' => (string)$entry['title'],
            'instructions' => 'Lesen Sie den Text und beantworten Sie die Fragen 1-5.',
            'sample_item' => [
                'text' => $sharedText,
                'question' => (string)$entry['question'],
                'options' => [
                    'A' => (string)$entry['options']['A'],
                    'B' => (string)$entry['options']['B'],
                    'C' => (string)$entry['options']['C'],
                ],
                'correct' => (string)$entry['correct'],
                'rationale' => (string)$entry['rationale'],
                'shuffle_options' => false,
            ],
        ];
    }

    return $templates;
}

function build_lesen_teil2_matching_templates(): array
{
    $anzeigen = [
        'A' => 'Bäckerei Stern: Wir suchen Aushilfe samstags 6-12 Uhr. Erfahrung nicht nötig.',
        'B' => 'Arztpraxis Weber: Termin nur mit Online-Anmeldung, keine telefonische Vergabe.',
        'C' => 'Wohnungsanzeige: 1-Zimmer, 420 Euro warm, frei ab sofort, Nähe Innenstadt.',
        'D' => 'Sprachschule Aktiv: B1-Abendkurs Mo/Mi 18:00-20:15, Start nächste Woche.',
        'E' => 'Stadtbibliothek: Lernraum täglich 9-20 Uhr, kostenlos mit Bibliotheksausweis.',
        'F' => 'Fahrschule Nord: Intensivkurs für Theorie in den Osterferien.',
        'G' => 'Sportverein West: Schwimmkurs für Erwachsene, dienstags 19 Uhr.',
        'H' => 'Reparaturdienst MobilFix: Handy-Reparatur am selben Tag, ohne Termin.',
    ];

    $anzeigenBlockLines = ["Anzeigen A-H:"];
    foreach ($anzeigen as $key => $text) {
        $anzeigenBlockLines[] = $key . ') ' . $text;
    }
    $anzeigenBlock = implode("\n", $anzeigenBlockLines);

    $situationen = [
        ['id' => 'l2_match_01', 'title' => 'Abendkurs Deutsch', 'text' => 'Sie arbeiten tagsüber und suchen einen Deutschkurs am Abend.', 'correct' => 'D'],
        ['id' => 'l2_match_02', 'title' => 'Schnelle Handy-Reparatur', 'text' => 'Ihr Handy ist kaputt. Sie möchten es heute noch reparieren lassen.', 'correct' => 'H'],
        ['id' => 'l2_match_03', 'title' => 'Samstagsjob', 'text' => 'Sie haben samstags frei und möchten ein paar Stunden arbeiten.', 'correct' => 'A'],
        ['id' => 'l2_match_04', 'title' => 'Günstige Wohnung', 'text' => 'Sie suchen sofort eine kleine und günstige Wohnung in Stadtnähe.', 'correct' => 'C'],
        ['id' => 'l2_match_05', 'title' => 'Lernort bis abends', 'text' => 'Sie brauchen einen ruhigen Platz zum Lernen, auch am Abend.', 'correct' => 'E'],
        ['id' => 'l2_match_06', 'title' => 'Schwimmen lernen', 'text' => 'Sie möchten als Erwachsene in einem Kurs schwimmen lernen.', 'correct' => 'G'],
        ['id' => 'l2_match_07', 'title' => 'Theorie schnell', 'text' => 'Sie wollen in den Ferien intensiv für die Führerschein-Theorie lernen.', 'correct' => 'F'],
        ['id' => 'l2_match_08', 'title' => 'Arzttermin online', 'text' => 'Sie möchten einen Arzttermin buchen und können das online machen.', 'correct' => 'B'],
        ['id' => 'l2_match_09', 'title' => 'Kinderkurs gesucht', 'text' => 'Sie suchen einen Schwimmkurs für Ihr 8-jähriges Kind.', 'correct' => 'X'],
        ['id' => 'l2_match_10', 'title' => 'Wohnung auf dem Land', 'text' => 'Sie möchten eine 3-Zimmer-Wohnung außerhalb der Stadt finden.', 'correct' => 'X'],
        ['id' => 'l2_match_11', 'title' => 'Nur Sonntags offen', 'text' => 'Sie brauchen einen Lernraum, der nur sonntags geöffnet ist.', 'correct' => 'X'],
        ['id' => 'l2_match_12', 'title' => 'Deutschkurs am Vormittag', 'text' => 'Sie möchten einen B1-Kurs nur morgens besuchen.', 'correct' => 'X'],
    ];

    $options = [
        'A' => 'Anzeige A',
        'B' => 'Anzeige B',
        'C' => 'Anzeige C',
        'D' => 'Anzeige D',
        'E' => 'Anzeige E',
        'F' => 'Anzeige F',
        'G' => 'Anzeige G',
        'H' => 'Anzeige H',
        'X' => 'keine passende Anzeige',
    ];

    $templates = [];
    foreach ($situationen as $entry) {
        $text = "Lesen Sie die Situationen und die Anzeigen A-H. Finden Sie die passende Anzeige.\n\n"
            . $anzeigenBlock
            . "\n\nSituation:\n"
            . $entry['text'];

        $templates[] = [
            'id' => (string)$entry['id'],
            'module' => 'lesen',
            'set_name' => 'DTZ Lesen Teil 2',
            'dtz_part' => 'L2 Situationen und Anzeigen',
            'task_type' => 'Zuordnung',
            'context' => 'Situationen 26-30',
            'title' => (string)$entry['title'],
            'instructions' => 'Lesen Sie die Situationen 26-30 und die Anzeigen A-H. Wählen Sie A-H oder X.',
            'sample_item' => [
                'text' => $text,
                'question' => 'Welche Anzeige passt?',
                'options' => $options,
                'correct' => (string)$entry['correct'],
                'rationale' => $entry['correct'] === 'X'
                    ? 'Für diese Situation gibt es keine passende Anzeige.'
                    : ('Passend ist Anzeige ' . $entry['correct'] . '.'),
                'shuffle_options' => false,
            ],
        ];
    }

    return $templates;
}

function extract_dtz_part_number(string $partLabel, string $module): int
{
    $prefix = normalize_training_module($module) === 'hoeren' ? 'H' : 'L';
    if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)/u', trim($partLabel), $m) !== 1) {
        return 0;
    }
    return (int)$m[1];
}

function get_training_templates(string $module, int $teil = 0): array
{
    $normalized = normalize_training_module($module);
    if ($normalized === '') {
        throw new RuntimeException('Ungültiges Modul angefordert.');
    }

    if ($normalized === 'lesen' && $teil === 1) {
        return build_lesen_teil1_text_templates();
    }

    if ($normalized === 'lesen' && $teil === 2) {
        return build_lesen_teil2_matching_templates();
    }

    $templates = $normalized === 'hoeren'
        ? build_clean_hoeren_templates()
        : build_clean_lesen_templates();

    if ($teil > 0) {
        $templates = array_values(array_filter($templates, static function (array $tpl) use ($normalized, $teil): bool {
            $partLabel = (string)($tpl['dtz_part'] ?? '');
            return extract_dtz_part_number($partLabel, $normalized) === $teil;
        }));
    }

    return $templates;
}

function clamp_training_count(int $max, int $count): int
{
    if ($count < 1) {
        $count = 1;
    }
    if ($count > $max) {
        $count = $max;
    }
    return $count;
}

function prepare_training_options(array $options, string $correct, bool $shuffleOptions = true): array
{
    $normalizedOptions = [];
    foreach ($options as $key => $value) {
        $label = strtoupper(trim((string)$key));
        if ($label === '') {
            continue;
        }
        $normalizedOptions[$label] = (string)$value;
    }

    if (!$normalizedOptions) {
        $normalizedOptions = [
            'A' => '',
            'B' => '',
            'C' => '',
        ];
    }

    $normalizedCorrect = strtoupper(trim($correct));
    if (!array_key_exists($normalizedCorrect, $normalizedOptions)) {
        $normalizedCorrect = array_key_first($normalizedOptions) ?: 'A';
    }

    $keys = array_keys($normalizedOptions);
    $isAbc = count($keys) === 3 && $keys === ['A', 'B', 'C'];
    if (!$shuffleOptions || !$isAbc) {
        return [
            'options' => $normalizedOptions,
            'correct' => $normalizedCorrect,
        ];
    }

    $pairs = [
        ['label' => 'A', 'text' => (string)$normalizedOptions['A']],
        ['label' => 'B', 'text' => (string)$normalizedOptions['B']],
        ['label' => 'C', 'text' => (string)$normalizedOptions['C']],
    ];

    for ($i = count($pairs) - 1; $i > 0; $i--) {
        try {
            $j = random_int(0, $i);
        } catch (Throwable $e) {
            $j = mt_rand(0, $i);
        }
        [$pairs[$i], $pairs[$j]] = [$pairs[$j], $pairs[$i]];
    }

    $outOptions = [];
    $letters = ['A', 'B', 'C'];
    $newCorrect = 'A';
    foreach ($pairs as $index => $pair) {
        $letter = $letters[$index];
        $outOptions[$letter] = (string)($pair['text'] ?? '');
        if ((string)$pair['label'] === $correct) {
            $newCorrect = $letter;
        }
    }

    return [
        'options' => $outOptions,
        'correct' => $newCorrect,
    ];
}

function pick_random_item(array $items): array
{
    if (!$items) {
        throw new RuntimeException('Keine Daten für Zufallsauswahl vorhanden.');
    }
    $max = count($items) - 1;
    try {
        $index = random_int(0, $max);
    } catch (Throwable $e) {
        $index = mt_rand(0, $max);
    }
    return (array)$items[$index];
}

function build_hoeren_teil_structured_pools(): array
{
    return [
        1 => [
            [
                'title' => 'Praxisansage: Terminänderung',
                'instructions' => 'Hören Sie den Text und wählen Sie zu jeder Aufgabe die richtige Lösung.',
                'audio_script' => 'Guten Tag, hier spricht die Gemeinschaftspraxis West. Frau Karaca kann morgen leider nicht kommen. Ihr neuer Termin ist am Donnerstag um 10:40 Uhr in Zimmer 2. Bitte bringen Sie die Versichertenkarte und den aktuellen Medikamentenplan mit. Wenn Sie den Termin nicht wahrnehmen können, rufen Sie uns heute bis 18 Uhr an.',
                'questions' => [
                    [
                        'question' => 'Wann ist der neue Termin?',
                        'options' => ['A' => 'am Donnerstag um 10:40 Uhr', 'B' => 'am Donnerstag um 9:40 Uhr', 'C' => 'am Freitag um 10:40 Uhr'],
                        'correct' => 'A',
                        'rationale' => 'In der Ansage steht: Donnerstag um 10:40 Uhr.'
                    ],
                    [
                        'question' => 'Wo findet der Termin statt?',
                        'options' => ['A' => 'in Zimmer 1', 'B' => 'in Zimmer 2', 'C' => 'an der Rezeption'],
                        'correct' => 'B',
                        'rationale' => 'Genannt wird Zimmer 2.'
                    ],
                    [
                        'question' => 'Was soll die Patientin mitbringen?',
                        'options' => ['A' => 'nur den Ausweis', 'B' => 'Versichertenkarte und Medikamentenplan', 'C' => 'nur den Medikamentenplan'],
                        'correct' => 'B',
                        'rationale' => 'Beide Unterlagen werden explizit genannt.'
                    ],
                    [
                        'question' => 'Bis wann soll sie anrufen, falls sie nicht kommen kann?',
                        'options' => ['A' => 'bis 16 Uhr', 'B' => 'bis 17 Uhr', 'C' => 'bis 18 Uhr'],
                        'correct' => 'C',
                        'rationale' => 'Die Ansage nennt heute bis 18 Uhr.'
                    ],
                ],
            ],
            [
                'title' => 'Kursansage: Raumwechsel',
                'instructions' => 'Hören Sie den Text und wählen Sie zu jeder Aufgabe die richtige Lösung.',
                'audio_script' => 'Achtung, eine Information der Sprachschule Linden. Der B1-Abendkurs beginnt heute nicht in Raum 4, sondern in Raum 7 im zweiten Stock. Beginn bleibt 18:15 Uhr. Die Lehrerin Frau Duman ist heute krank, deshalb übernimmt Herr Klein den Unterricht. Das Arbeitsblatt erhalten Sie vor dem Kurs am Empfang.',
                'questions' => [
                    [
                        'question' => 'Was hat sich geändert?',
                        'options' => ['A' => 'die Uhrzeit', 'B' => 'der Raum', 'C' => 'das Kursniveau'],
                        'correct' => 'B',
                        'rationale' => 'Nur der Raum wurde geändert.'
                    ],
                    [
                        'question' => 'Wo ist der neue Raum?',
                        'options' => ['A' => 'Raum 7 im zweiten Stock', 'B' => 'Raum 7 im ersten Stock', 'C' => 'Raum 4 im zweiten Stock'],
                        'correct' => 'A',
                        'rationale' => 'So wird der neue Raum genannt.'
                    ],
                    [
                        'question' => 'Wer unterrichtet heute?',
                        'options' => ['A' => 'Frau Duman', 'B' => 'Herr Klein', 'C' => 'Herr Duman'],
                        'correct' => 'B',
                        'rationale' => 'Herr Klein übernimmt den Unterricht.'
                    ],
                    [
                        'question' => 'Wo bekommt man das Arbeitsblatt?',
                        'options' => ['A' => 'im Klassenzimmer', 'B' => 'am Empfang', 'C' => 'online'],
                        'correct' => 'B',
                        'rationale' => 'Das Arbeitsblatt gibt es am Empfang.'
                    ],
                ],
            ],
            [
                'title' => 'Telefonansage: Wohnungsbesichtigung',
                'instructions' => 'Hören Sie den Text und wählen Sie zu jeder Aufgabe die richtige Lösung.',
                'audio_script' => 'Guten Tag, hier ist die Hausverwaltung Nord. Wegen eines Wasserschadens kann die Besichtigung am Montag nicht stattfinden. Der neue Termin ist am Mittwoch um 17:30 Uhr in der Lindenstraße 22. Bitte melden Sie sich am Haupteingang bei Herrn Alkan. Falls Sie nicht kommen können, schicken Sie uns bis Dienstag 12 Uhr eine E-Mail.',
                'questions' => [
                    [
                        'question' => 'Warum wird der erste Termin geändert?',
                        'options' => ['A' => 'wegen Krankheit', 'B' => 'wegen eines Wasserschadens', 'C' => 'wegen Urlaub'],
                        'correct' => 'B',
                        'rationale' => 'In der Ansage wird ein Wasserschaden als Grund genannt.'
                    ],
                    [
                        'question' => 'Wann ist der neue Termin?',
                        'options' => ['A' => 'am Mittwoch um 17:30 Uhr', 'B' => 'am Dienstag um 17:30 Uhr', 'C' => 'am Mittwoch um 18:30 Uhr'],
                        'correct' => 'A',
                        'rationale' => 'Genannt wird Mittwoch, 17:30 Uhr.'
                    ],
                    [
                        'question' => 'Bei wem soll man sich melden?',
                        'options' => ['A' => 'bei Frau Duman', 'B' => 'bei Herrn Alkan', 'C' => 'beim Hausmeister ohne Namen'],
                        'correct' => 'B',
                        'rationale' => 'Die Ansage sagt ausdrücklich: bei Herrn Alkan.'
                    ],
                    [
                        'question' => 'Bis wann soll man absagen?',
                        'options' => ['A' => 'bis Dienstag 12 Uhr', 'B' => 'bis Mittwoch 12 Uhr', 'C' => 'bis Montag 12 Uhr'],
                        'correct' => 'A',
                        'rationale' => 'Absagefrist ist Dienstag, 12 Uhr.'
                    ],
                ],
            ],
        ],
        2 => [
            [
                'title' => 'Radiobeitrag: Stadtverkehr',
                'instructions' => 'Hören Sie den Text und wählen Sie zu jeder Aufgabe die richtige Lösung.',
                'audio_script' => 'Hier ist Radio Süd mit den Verkehrsmeldungen für die Region. Auf der A5 zwischen Langen und Nordstadt gibt es wegen eines Unfalls eine Vollsperrung. Die Umleitung ist ausgeschildert. Im Zentrum fährt die Straßenbahnlinie 3 heute nur bis Marktplatz. Außerdem fallen zwischen 17 und 19 Uhr mehrere Regionalzüge Richtung Hafen aus. Reisende nach Hafen sollen den Bus 26 nehmen.',
                'questions' => [
                    [
                        'question' => 'Warum ist die A5 gesperrt?',
                        'options' => ['A' => 'wegen Bauarbeiten', 'B' => 'wegen eines Unfalls', 'C' => 'wegen einer Veranstaltung'],
                        'correct' => 'B',
                        'rationale' => 'Die Meldung nennt einen Unfall.'
                    ],
                    [
                        'question' => 'Wie weit fährt die Linie 3?',
                        'options' => ['A' => 'bis Marktplatz', 'B' => 'bis Hafen', 'C' => 'bis Hauptbahnhof'],
                        'correct' => 'A',
                        'rationale' => 'Linie 3 endet heute am Marktplatz.'
                    ],
                    [
                        'question' => 'Wann fallen Regionalzüge Richtung Hafen aus?',
                        'options' => ['A' => 'zwischen 16 und 18 Uhr', 'B' => 'zwischen 17 und 19 Uhr', 'C' => 'zwischen 18 und 20 Uhr'],
                        'correct' => 'B',
                        'rationale' => 'Genau dieses Zeitfenster wird genannt.'
                    ],
                    [
                        'question' => 'Welche Alternative wird empfohlen?',
                        'options' => ['A' => 'Bus 26', 'B' => 'Bus 6', 'C' => 'Straßenbahn 1'],
                        'correct' => 'A',
                        'rationale' => 'Für Hafen wird Bus 26 empfohlen.'
                    ],
                    [
                        'question' => 'Wo gibt es eine Umleitung?',
                        'options' => ['A' => 'im Zentrum', 'B' => 'auf der A5', 'C' => 'am Marktplatz'],
                        'correct' => 'B',
                        'rationale' => 'Die Umleitung gehört zur A5-Sperrung.'
                    ],
                ],
            ],
            [
                'title' => 'Infokanal: Wetter und Veranstaltung',
                'instructions' => 'Hören Sie den Text und wählen Sie zu jeder Aufgabe die richtige Lösung.',
                'audio_script' => 'Guten Abend. Morgen bleibt es vormittags trocken, ab 13 Uhr gibt es in vielen Stadtteilen Regen. Die Temperaturen liegen zwischen 9 und 14 Grad. Das Frühlingsfest im Stadtpark beginnt trotzdem wie geplant um 15 Uhr, aber das Konzert wird in die Sporthalle verlegt. Besucherinnen und Besucher sollen den Eingang Nord benutzen.',
                'questions' => [
                    [
                        'question' => 'Ab wann wird Regen gemeldet?',
                        'options' => ['A' => 'ab 11 Uhr', 'B' => 'ab 12 Uhr', 'C' => 'ab 13 Uhr'],
                        'correct' => 'C',
                        'rationale' => 'Regen ab 13 Uhr.'
                    ],
                    [
                        'question' => 'Wie hoch sind die Temperaturen?',
                        'options' => ['A' => 'zwischen 9 und 14 Grad', 'B' => 'zwischen 6 und 12 Grad', 'C' => 'zwischen 10 und 16 Grad'],
                        'correct' => 'A',
                        'rationale' => 'Die Spanne ist 9 bis 14 Grad.'
                    ],
                    [
                        'question' => 'Was passiert mit dem Konzert?',
                        'options' => ['A' => 'es fällt aus', 'B' => 'es wird verlegt', 'C' => 'es beginnt später im Park'],
                        'correct' => 'B',
                        'rationale' => 'Das Konzert wird in die Sporthalle verlegt.'
                    ],
                    [
                        'question' => 'Wann beginnt das Frühlingsfest?',
                        'options' => ['A' => 'um 14 Uhr', 'B' => 'um 15 Uhr', 'C' => 'um 16 Uhr'],
                        'correct' => 'B',
                        'rationale' => 'Beginn bleibt 15 Uhr.'
                    ],
                    [
                        'question' => 'Welcher Eingang wird empfohlen?',
                        'options' => ['A' => 'Eingang Süd', 'B' => 'Eingang West', 'C' => 'Eingang Nord'],
                        'correct' => 'C',
                        'rationale' => 'Genannt wird Eingang Nord.'
                    ],
                ],
            ],
            [
                'title' => 'Serviceinformation: Bürgeramt und Bibliothek',
                'instructions' => 'Hören Sie den Text und wählen Sie zu jeder Aufgabe die richtige Lösung.',
                'audio_script' => 'Guten Morgen, hier sind die Servicehinweise der Stadt. Das Bürgeramt am Rathaus öffnet heute erst um 10 Uhr, weil das Computersystem gewartet wird. Bereits vereinbarte Termine bleiben gültig. Die Stadtbibliothek ist wegen Inventur von Montag bis Mittwoch geschlossen. Rückgaben sind in dieser Zeit nur am Automaten neben dem Haupteingang möglich. Am Donnerstag sind beide Einrichtungen wieder normal geöffnet.',
                'questions' => [
                    [
                        'question' => 'Warum öffnet das Bürgeramt später?',
                        'options' => ['A' => 'wegen Personalmangel', 'B' => 'wegen einer Systemwartung', 'C' => 'wegen eines Streiks'],
                        'correct' => 'B',
                        'rationale' => 'Grund ist die Wartung des Computersystems.'
                    ],
                    [
                        'question' => 'Was passiert mit bereits vereinbarten Terminen?',
                        'options' => ['A' => 'Sie werden abgesagt.', 'B' => 'Sie bleiben gültig.', 'C' => 'Sie werden auf Donnerstag verschoben.'],
                        'correct' => 'B',
                        'rationale' => 'In der Meldung steht, dass sie gültig bleiben.'
                    ],
                    [
                        'question' => 'Wann ist die Bibliothek geschlossen?',
                        'options' => ['A' => 'Montag bis Mittwoch', 'B' => 'Dienstag bis Donnerstag', 'C' => 'nur am Montag'],
                        'correct' => 'A',
                        'rationale' => 'Die Schließung gilt von Montag bis Mittwoch.'
                    ],
                    [
                        'question' => 'Wie kann man Medien trotzdem zurückgeben?',
                        'options' => ['A' => 'nur per Post', 'B' => 'am Rückgabeautomaten', 'C' => 'gar nicht'],
                        'correct' => 'B',
                        'rationale' => 'Rückgaben sind am Automaten möglich.'
                    ],
                    [
                        'question' => 'Ab wann ist wieder normal geöffnet?',
                        'options' => ['A' => 'ab Mittwoch', 'B' => 'ab Donnerstag', 'C' => 'ab Freitag'],
                        'correct' => 'B',
                        'rationale' => 'Am Donnerstag öffnen beide normal.'
                    ],
                ],
            ],
        ],
        3 => [
            [
                'title' => 'Alltagsdialoge',
                'instructions' => 'Sie hören 4 kurze Dialoge. Entscheiden Sie zuerst Richtig/Falsch und beantworten Sie dann die Detailfrage.',
                'dialogs' => [
                    [
                        'title' => 'Dialog 1',
                        'audio_script' => 'WOMAN_1: Hallo Herr Becker, ich komme heute zehn Minuten später in den Kurs. MAN_1: Kein Problem, wir starten mit Wiederholung.',
                        'speaker_meta' => ['woman_1', 'man_1'],
                        'true_false' => [
                            'statement' => 'Die Frau kommt pünktlich zum Kurs.',
                            'correct' => 'B',
                            'rationale' => 'Sie sagt, dass sie später kommt.'
                        ],
                        'detail' => [
                            'question' => 'Womit startet der Kurs?',
                            'options' => ['A' => 'mit einem Test', 'B' => 'mit Wiederholung', 'C' => 'mit Gruppenarbeit'],
                            'correct' => 'B',
                            'rationale' => 'Der Mann sagt: Wir starten mit Wiederholung.'
                        ],
                    ],
                    [
                        'title' => 'Dialog 2',
                        'audio_script' => 'MAN_1: Guten Tag, ich habe für morgen einen Termin beim Jobcenter. WOMAN_2: Der Termin bleibt, aber bitte bringen Sie den Mietvertrag mit.',
                        'speaker_meta' => ['man_1', 'woman_2'],
                        'true_false' => [
                            'statement' => 'Der Termin beim Jobcenter wird abgesagt.',
                            'correct' => 'B',
                            'rationale' => 'Der Termin bleibt bestehen.'
                        ],
                        'detail' => [
                            'question' => 'Was soll der Mann mitbringen?',
                            'options' => ['A' => 'den Arbeitsvertrag', 'B' => 'den Ausweis', 'C' => 'den Mietvertrag'],
                            'correct' => 'C',
                            'rationale' => 'Die Frau nennt den Mietvertrag.'
                        ],
                    ],
                    [
                        'title' => 'Dialog 3',
                        'audio_script' => 'WOMAN_2: Entschuldigung, fährt dieser Bus zum Krankenhaus? MAN_1: Ja, aber Sie müssen am Rathaus umsteigen.',
                        'speaker_meta' => ['woman_2', 'man_1'],
                        'true_false' => [
                            'statement' => 'Man fährt ohne Umsteigen zum Krankenhaus.',
                            'correct' => 'B',
                            'rationale' => 'Es ist ein Umstieg nötig.'
                        ],
                        'detail' => [
                            'question' => 'Wo soll die Frau umsteigen?',
                            'options' => ['A' => 'am Rathaus', 'B' => 'am Bahnhof', 'C' => 'am Marktplatz'],
                            'correct' => 'A',
                            'rationale' => 'Genannt wird das Rathaus.'
                        ],
                    ],
                    [
                        'title' => 'Dialog 4',
                        'audio_script' => 'NARRATOR: Im Supermarkt fragt ein Kunde nach Brot. WOMAN_1: Frisches Brot gibt es wieder ab 17 Uhr.',
                        'speaker_meta' => ['narrator', 'woman_1'],
                        'true_false' => [
                            'statement' => 'Frisches Brot gibt es sofort.',
                            'correct' => 'B',
                            'rationale' => 'Es gibt Brot erst ab 17 Uhr.'
                        ],
                        'detail' => [
                            'question' => 'Ab wann gibt es frisches Brot?',
                            'options' => ['A' => 'ab 16 Uhr', 'B' => 'ab 17 Uhr', 'C' => 'ab 18 Uhr'],
                            'correct' => 'B',
                            'rationale' => 'Die Verkäuferin nennt 17 Uhr.'
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Dialoge aus Alltag und Kurs',
                'instructions' => 'Sie hören 4 kurze Dialoge. Entscheiden Sie zuerst Richtig/Falsch und beantworten Sie dann die Detailfrage.',
                'dialogs' => [
                    [
                        'title' => 'Dialog 1',
                        'audio_script' => 'WOMAN_1: Entschuldigung, fährt die Bahn zum Hauptbahnhof? MAN_1: Ja, aber heute nur bis Berliner Platz. Dort müssen Sie in die Linie 8 umsteigen.',
                        'speaker_meta' => ['woman_1', 'man_1'],
                        'true_false' => [
                            'statement' => 'Die Frau kann ohne Umsteigen bis zum Hauptbahnhof fahren.',
                            'correct' => 'B',
                            'rationale' => 'Ein Umstieg am Berliner Platz ist nötig.'
                        ],
                        'detail' => [
                            'question' => 'In welche Linie soll die Frau umsteigen?',
                            'options' => ['A' => 'Linie 6', 'B' => 'Linie 7', 'C' => 'Linie 8'],
                            'correct' => 'C',
                            'rationale' => 'Der Mann nennt Linie 8.'
                        ],
                    ],
                    [
                        'title' => 'Dialog 2',
                        'audio_script' => 'MAN_1: Guten Tag, ich rufe wegen meiner Bewerbung an. WOMAN_2: Ja, wir haben Ihre Unterlagen erhalten. Das Vorstellungsgespräch ist am Freitag um 9 Uhr.',
                        'speaker_meta' => ['man_1', 'woman_2'],
                        'true_false' => [
                            'statement' => 'Die Unterlagen des Mannes sind nicht angekommen.',
                            'correct' => 'B',
                            'rationale' => 'Die Frau bestätigt den Eingang der Unterlagen.'
                        ],
                        'detail' => [
                            'question' => 'Wann ist das Vorstellungsgespräch?',
                            'options' => ['A' => 'am Freitag um 9 Uhr', 'B' => 'am Freitag um 10 Uhr', 'C' => 'am Donnerstag um 9 Uhr'],
                            'correct' => 'A',
                            'rationale' => 'Termin ist Freitag, 9 Uhr.'
                        ],
                    ],
                    [
                        'title' => 'Dialog 3',
                        'audio_script' => 'WOMAN_2: Herr Demir, Ihr Deutschkurs beginnt nächste Woche schon um 8:30 Uhr. MAN_1: Danke. Dann komme ich mit dem früheren Bus.',
                        'speaker_meta' => ['woman_2', 'man_1'],
                        'true_false' => [
                            'statement' => 'Der Kurs beginnt nächste Woche später als bisher.',
                            'correct' => 'B',
                            'rationale' => 'Der Kurs beginnt früher, nicht später.'
                        ],
                        'detail' => [
                            'question' => 'Wie will Herr Demir kommen?',
                            'options' => ['A' => 'zu Fuß', 'B' => 'mit dem früheren Bus', 'C' => 'mit dem Taxi'],
                            'correct' => 'B',
                            'rationale' => 'Er sagt: mit dem früheren Bus.'
                        ],
                    ],
                    [
                        'title' => 'Dialog 4',
                        'audio_script' => 'NARRATOR: In der Apotheke fragt eine Kundin nach Medikamenten. WOMAN_1: Dieses Mittel ist heute ausverkauft. Wir bekommen morgen Vormittag eine neue Lieferung.',
                        'speaker_meta' => ['narrator', 'woman_1'],
                        'true_false' => [
                            'statement' => 'Das Medikament ist heute noch verfügbar.',
                            'correct' => 'B',
                            'rationale' => 'Es ist heute ausverkauft.'
                        ],
                        'detail' => [
                            'question' => 'Wann kommt die neue Lieferung?',
                            'options' => ['A' => 'morgen Vormittag', 'B' => 'heute Abend', 'C' => 'nächste Woche'],
                            'correct' => 'A',
                            'rationale' => 'Die Lieferung kommt morgen Vormittag.'
                        ],
                    ],
                ],
            ],
        ],
        4 => [
            [
                'title' => 'Meinungen zu Kurszeiten',
                'instructions' => 'Sie hören kurze Aussagen. Ordnen Sie jeder Aussage die passende Antwort zu.',
                'allow_reuse' => false,
                'options' => [
                    'A' => 'Sie arbeitet morgens und braucht einen Abendkurs.',
                    'B' => 'Er möchte am Samstag lernen.',
                    'C' => 'Sie lernt lieber online.',
                    'D' => 'Er braucht einen Kurs in der Nähe.',
                    'E' => 'Sie kann nur zweimal pro Woche kommen.',
                ],
                'statements' => [
                    [
                        'title' => 'Aussage 1',
                        'audio_script' => 'WOMAN_1: Unter der Woche habe ich Frühschicht. Für mich ist ein Kurs ab 18 Uhr ideal.',
                        'speaker' => 'woman_1',
                        'correct' => 'A',
                        'rationale' => 'Die Aussage passt zu einem Abendkurs.'
                    ],
                    [
                        'title' => 'Aussage 2',
                        'audio_script' => 'MAN_1: Ich wohne ohne Auto. Der Kurs darf nicht weit von meiner Wohnung sein.',
                        'speaker' => 'man_1',
                        'correct' => 'D',
                        'rationale' => 'Er betont die Nähe.'
                    ],
                    [
                        'title' => 'Aussage 3',
                        'audio_script' => 'WOMAN_2: Ich habe Kinder und kann nur am Dienstag und Donnerstag teilnehmen.',
                        'speaker' => 'woman_2',
                        'correct' => 'E',
                        'rationale' => 'Sie kann nur an zwei Tagen.'
                    ],
                    [
                        'title' => 'Aussage 4',
                        'audio_script' => 'MAN_1: Ich arbeite von Montag bis Freitag. Für mich kommt ein Kurs am Wochenende in Frage.',
                        'speaker' => 'man_1',
                        'correct' => 'B',
                        'rationale' => 'Er möchte am Samstag lernen.'
                    ],
                    [
                        'title' => 'Aussage 5',
                        'audio_script' => 'WOMAN_1: Wegen der langen Anfahrt suche ich lieber einen digitalen Kurs am Laptop.',
                        'speaker' => 'woman_1',
                        'correct' => 'C',
                        'rationale' => 'Sie bevorzugt online.'
                    ],
                ],
            ],
            [
                'title' => 'Aussagen zu Arbeit und Terminen',
                'instructions' => 'Sie hören kurze Aussagen. Ordnen Sie jeder Aussage die passende Antwort zu.',
                'allow_reuse' => false,
                'options' => [
                    'A' => 'Sie braucht einen Termin am frühen Morgen.',
                    'B' => 'Er sucht eine Stelle in Teilzeit.',
                    'C' => 'Sie kann nur digital an Besprechungen teilnehmen.',
                    'D' => 'Er möchte näher an seinem Arbeitsplatz wohnen.',
                    'E' => 'Sie braucht Unterstützung bei Formularen.'
                ],
                'statements' => [
                    [
                        'title' => 'Aussage 1',
                        'audio_script' => 'WOMAN_1: Ich beginne um sieben Uhr mit der Arbeit. Deshalb brauche ich den Termin möglichst vor acht Uhr.',
                        'speaker' => 'woman_1',
                        'correct' => 'A',
                        'rationale' => 'Sie sucht einen sehr frühen Termin.'
                    ],
                    [
                        'title' => 'Aussage 2',
                        'audio_script' => 'MAN_1: Vollzeit ist für mich aktuell nicht möglich. Ich suche einen Job mit etwa zwanzig Stunden pro Woche.',
                        'speaker' => 'man_1',
                        'correct' => 'B',
                        'rationale' => 'Er beschreibt ausdrücklich eine Teilzeitstelle.'
                    ],
                    [
                        'title' => 'Aussage 3',
                        'audio_script' => 'WOMAN_2: Wegen meines kleinen Kindes kann ich nicht ins Büro fahren. Online-Termine passen für mich am besten.',
                        'speaker' => 'woman_2',
                        'correct' => 'C',
                        'rationale' => 'Sie bevorzugt digitale Termine.'
                    ],
                    [
                        'title' => 'Aussage 4',
                        'audio_script' => 'MAN_1: Jeden Tag fahre ich fast eine Stunde zur Firma. Ich suche deshalb eine Wohnung in der Nähe.',
                        'speaker' => 'man_1',
                        'correct' => 'D',
                        'rationale' => 'Er möchte näher am Arbeitsplatz wohnen.'
                    ],
                    [
                        'title' => 'Aussage 5',
                        'audio_script' => 'WOMAN_1: Ich habe den Antrag gelesen, aber ich verstehe viele Felder nicht. Ich brauche Hilfe beim Ausfüllen.',
                        'speaker' => 'woman_1',
                        'correct' => 'E',
                        'rationale' => 'Sie braucht Hilfe mit Formularen.'
                    ],
                ],
            ],
        ],
    ];
}

function create_hoeren_structured_set(int $teil, bool $includeExplanation): array
{
    $pools = build_hoeren_teil_structured_pools();
    $pool = (array)($pools[$teil] ?? []);
    if (!$pool) {
        throw new RuntimeException('Für diesen Hören-Teil sind keine strukturierten Aufgaben verfügbar.');
    }

    $picked = pick_random_item($pool);
    $schemaMap = [
        1 => 'hoeren_teil1_bundle',
        2 => 'hoeren_teil2_bundle',
        3 => 'hoeren_teil3_dialogcards',
        4 => 'hoeren_teil4_matching',
    ];
    $dtzPartMap = [
        1 => 'H1 Kurze Ansagen',
        2 => 'H2 Informationen',
        3 => 'H3 Dialoge',
        4 => 'H4 Aussagen zuordnen',
    ];
    $schema = (string)($schemaMap[$teil] ?? 'hoeren_bundle');
    $item = [
        'set_index' => 1,
        'template_id' => 'hoeren_structured_t' . $teil . '_' . substr(sha1((string)json_encode($picked)), 0, 8),
        'dtz_schema' => $schema,
        'dtz_part' => $dtzPartMap[$teil] ?? ('H' . $teil),
        'title' => germanize_umlauts_text((string)($picked['title'] ?? 'Hören-Aufgabe')),
        'instructions' => germanize_umlauts_text((string)($picked['instructions'] ?? '')),
    ];
    $expectedCounts = [
        1 => 4, // Teil 1: 4 MC-Aufgaben
        2 => 5, // Teil 2: 5 MC-Aufgaben
        3 => 4, // Teil 3: 4 Dialogkarten (je 2 Items)
        4 => 5, // Teil 4: 5 Zuordnungen
    ];

    if ($teil === 1 || $teil === 2) {
        $item['audio_script'] = germanize_umlauts_text((string)($picked['audio_script'] ?? ''));
        $item['speaker_meta'] = ['narrator'];
        $questions = [];
        $rawQuestions = array_values((array)($picked['questions'] ?? []));
        $expected = (int)($expectedCounts[$teil] ?? 0);
        if ($expected > 0) {
            if (count($rawQuestions) < $expected) {
                throw new RuntimeException('Für Hören Teil ' . $teil . ' sind weniger als ' . $expected . ' Fragen konfiguriert.');
            }
            $rawQuestions = array_slice($rawQuestions, 0, $expected);
        }
        foreach ($rawQuestions as $idx => $question) {
            $prepared = prepare_training_options(
                (array)($question['options'] ?? []),
                (string)($question['correct'] ?? ''),
                true
            );
            $questions[] = [
                'id' => 'q_' . $idx,
                'question' => germanize_umlauts_text((string)($question['question'] ?? '')),
                'options' => array_map(static fn($v) => germanize_umlauts_text((string)$v), (array)$prepared['options']),
                'correct' => (string)$prepared['correct'],
                'explanation' => $includeExplanation ? germanize_umlauts_text((string)($question['rationale'] ?? '')) : '',
            ];
        }
        $item['questions'] = $questions;
    } elseif ($teil === 3) {
        $dialogs = [];
        $rawDialogs = array_values((array)($picked['dialogs'] ?? []));
        $expected = (int)($expectedCounts[$teil] ?? 0);
        if ($expected > 0) {
            if (count($rawDialogs) < $expected) {
                throw new RuntimeException('Für Hören Teil 3 sind weniger als ' . $expected . ' Dialoge konfiguriert.');
            }
            $rawDialogs = array_slice($rawDialogs, 0, $expected);
        }
        foreach ($rawDialogs as $idx => $dialog) {
            $tf = (array)($dialog['true_false'] ?? []);
            $detail = (array)($dialog['detail'] ?? []);
            $preparedDetail = prepare_training_options(
                (array)($detail['options'] ?? []),
                (string)($detail['correct'] ?? ''),
                true
            );
            $dialogs[] = [
                'id' => 'd_' . ($idx + 1),
                'title' => germanize_umlauts_text((string)($dialog['title'] ?? ('Dialog ' . ($idx + 1)))),
                'audio_script' => germanize_umlauts_text((string)($dialog['audio_script'] ?? '')),
                'speaker_meta' => array_values(array_map(static fn($v) => lower_text((string)$v), (array)($dialog['speaker_meta'] ?? []))),
                'true_false' => [
                    'statement' => germanize_umlauts_text((string)($tf['statement'] ?? '')),
                    'correct' => (string)($tf['correct'] ?? ''),
                    'explanation' => $includeExplanation ? germanize_umlauts_text((string)($tf['rationale'] ?? '')) : '',
                ],
                'detail' => [
                    'question' => germanize_umlauts_text((string)($detail['question'] ?? '')),
                    'options' => array_map(static fn($v) => germanize_umlauts_text((string)$v), (array)$preparedDetail['options']),
                    'correct' => (string)$preparedDetail['correct'],
                    'explanation' => $includeExplanation ? germanize_umlauts_text((string)($detail['rationale'] ?? '')) : '',
                ],
            ];
        }
        $item['dialogs'] = $dialogs;
    } elseif ($teil === 4) {
        $options = [];
        foreach ((array)($picked['options'] ?? []) as $label => $text) {
            $options[strtoupper((string)$label)] = germanize_umlauts_text((string)$text);
        }
        $statements = [];
        $rawStatements = array_values((array)($picked['statements'] ?? []));
        $expected = (int)($expectedCounts[$teil] ?? 0);
        if ($expected > 0) {
            if (count($rawStatements) < $expected) {
                throw new RuntimeException('Für Hören Teil 4 sind weniger als ' . $expected . ' Aussagen konfiguriert.');
            }
            $rawStatements = array_slice($rawStatements, 0, $expected);
        }
        foreach ($rawStatements as $idx => $statement) {
            $statements[] = [
                'id' => 's_' . ($idx + 1),
                'title' => germanize_umlauts_text((string)($statement['title'] ?? ('Aussage ' . ($idx + 1)))),
                'audio_script' => germanize_umlauts_text((string)($statement['audio_script'] ?? '')),
                'speaker' => lower_text((string)($statement['speaker'] ?? 'narrator')),
                'question' => 'Welche Antwort passt?',
                'correct' => strtoupper((string)($statement['correct'] ?? '')),
                'explanation' => $includeExplanation ? germanize_umlauts_text((string)($statement['rationale'] ?? '')) : '',
            ];
        }
        $item['allow_reuse'] = (bool)($picked['allow_reuse'] ?? false);
        $item['options'] = $options;
        $item['statements'] = $statements;
    }

    return [
        'module' => 'hoeren',
        'teil' => $teil,
        'count' => 1,
        'include_explanation' => $includeExplanation,
        'generated_at' => gmdate('c'),
        'items' => [$item],
    ];
}

/**
 * DTZ-Audit (Lesen Teil 3):
 * Alt: generische Single-Choice-Liste ohne Textblock-Logik.
 * Neu: drei Textblöcke, pro Text genau 2 Aufgaben (richtig/falsch + A/B/C),
 * Nummerierung 31-36 im DTZ-Stil.
 */
function build_lesen_teil3_textblock_pools(): array
{
    return [
        [
            'title' => 'Freizeit im Jugendzentrum',
            'instructions' => 'Lesen Sie die drei Texte. Zu jedem Text gibt es zwei Aufgaben. Entscheiden Sie bei jedem Text, ob die Aussage richtig oder falsch ist und welche Antwort (a, b oder c) am besten passt. Markieren Sie Ihre Lösungen für die Aufgaben 31-36.',
            'blocks' => [
                [
                    'text' => 'Lust auf Spiele? Das Jugendzentrum Pelikan lädt Jugendliche ab zehn Jahren jeden Mittwoch und Freitag von 15 bis 18 Uhr zum „Spieltreff“ ein. In den Sommerferien startet das Angebot täglich ab 10 Uhr. Es gibt Brett- und Kartenspiele sowie bei gutem Wetter Ballspiele im Garten. Ein Quiz findet nur freitags im Computerraum statt. Geld kann man dabei nicht gewinnen, aber man lernt viel und hat Spaß. Wer mitmachen möchte, meldet sich per E-Mail oder telefonisch an.',
                    'true_false' => [
                        'no' => 31,
                        'statement' => 'Das Quiz gibt es jeden Tag im Jugendzentrum.',
                        'correct' => 'B',
                        'rationale' => 'Im Text steht: Das Quiz findet nur freitags statt.',
                    ],
                    'mc' => [
                        'no' => 32,
                        'question' => 'Was ist beim Spieltreff richtig?',
                        'options' => [
                            'A' => 'Man gewinnt dort Geldpreise.',
                            'B' => 'Man kann bei gutem Wetter draußen spielen.',
                            'C' => 'Mitmachen ist erst ab 16 Jahren möglich.',
                        ],
                        'correct' => 'B',
                        'rationale' => 'Bei gutem Wetter finden Ballspiele im Garten statt.',
                    ],
                ],
                [
                    'text' => 'Liebe Eltern, die Klassenfahrt der 5a, die im Herbst ausgefallen ist, wird vom 12. bis 17. Mai nachgeholt. Zusammen mit der 5c fährt die Klasse in den Naturpark Bayerischer Wald. Die Kinder übernachten in einer Jugendherberge und machen Ausflüge. Am 20.2. um 18 Uhr gibt es einen Informationsabend über das Schulportal. Dort werden Packliste, Regeln und Organisation besprochen. Bitte schreiben Sie kurz per E-Mail, ob Sie teilnehmen können.',
                    'true_false' => [
                        'no' => 33,
                        'statement' => 'Die Klassenfahrt wurde endgültig abgesagt.',
                        'correct' => 'B',
                        'rationale' => 'Die Fahrt wird nachgeholt, nicht abgesagt.',
                    ],
                    'mc' => [
                        'no' => 34,
                        'question' => 'Was sollen die Eltern tun?',
                        'options' => [
                            'A' => 'Sie sollen am 20.2. in der Schule erscheinen.',
                            'B' => 'Sie sollen den Kindern neue Handys kaufen.',
                            'C' => 'Sie sollen online am Informationsabend teilnehmen.',
                        ],
                        'correct' => 'C',
                        'rationale' => 'Der Informationsabend findet online über das Schulportal statt.',
                    ],
                ],
                [
                    'text' => 'Sehr geehrter Herr Ronin, vielen Dank für Ihre Anmeldung zu unserem Gesundheits-Plus-Service. Mit der Karte erhalten Sie 2 % Sofortrabatt auf Einkäufe (außer rezeptpflichtige Medikamente), kostenlosen Versand bei Online-Bestellungen und Erinnerungen für Folgerezepte. Zusätzlich bieten wir Serviceleistungen wie Blutdruck- und Blutzuckermessung an. Ein Kundenmagazin und ein monatlicher Newsletter kommen per E-Mail. Der Service ist kostenlos und kann jederzeit schriftlich gekündigt werden.',
                    'true_false' => [
                        'no' => 35,
                        'statement' => 'Die Nachricht kommt vom Apothekenverbund.',
                        'correct' => 'A',
                        'rationale' => 'Es handelt sich um einen Gesundheitsservice einer Apotheke.',
                    ],
                    'mc' => [
                        'no' => 36,
                        'question' => 'Welchen Vorteil nennt der Text?',
                        'options' => [
                            'A' => 'Alle verschreibungspflichtigen Medikamente sind gratis.',
                            'B' => 'Online-Bestellungen werden kostenlos versendet.',
                            'C' => 'Die Karte gilt nur in einer Filiale.',
                        ],
                        'correct' => 'B',
                        'rationale' => 'Kostenloser Versand bei Online-Bestellungen wird ausdrücklich genannt.',
                    ],
                ],
            ],
        ],
        [
            'title' => 'Alltag: Termin, Schule und Service',
            'instructions' => 'Lesen Sie die drei Texte. Zu jedem Text gibt es zwei Aufgaben. Entscheiden Sie bei jedem Text, ob die Aussage richtig oder falsch ist und welche Antwort (a, b oder c) am besten passt. Markieren Sie Ihre Lösungen für die Aufgaben 31-36.',
            'blocks' => [
                [
                    'text' => 'Die Stadtbibliothek erweitert ab Mai ihre Öffnungszeiten. Von Montag bis Freitag ist jetzt von 8 bis 20 Uhr geöffnet, samstags von 10 bis 16 Uhr. Die Rückgabe am Automaten bleibt rund um die Uhr möglich. Für neue Nutzerinnen und Nutzer ist ein Ausweis nötig; die Ausstellung kostet einmalig 10 Euro. Wer den Ausweis online verlängern möchte, kann dies im Kundenkonto machen und die Gebühr per Lastschrift bezahlen.',
                    'true_false' => [
                        'no' => 31,
                        'statement' => 'Samstags ist die Bibliothek bis 20 Uhr geöffnet.',
                        'correct' => 'B',
                        'rationale' => 'Samstags gilt 10 bis 16 Uhr.',
                    ],
                    'mc' => [
                        'no' => 32,
                        'question' => 'Was ist laut Text richtig?',
                        'options' => [
                            'A' => 'Die Rückgabe ist nur während der Öffnungszeit möglich.',
                            'B' => 'Ein neuer Ausweis kostet einmalig 10 Euro.',
                            'C' => 'Die Online-Verlängerung ist nicht erlaubt.',
                        ],
                        'correct' => 'B',
                        'rationale' => 'Die Ausstellung kostet einmalig 10 Euro.',
                    ],
                ],
                [
                    'text' => 'Liebe Eltern, unser Elternabend findet am Donnerstag, den 14. März, um 19 Uhr statt. Wir sprechen über den Lernstand der Klasse und die Vorbereitung auf die Projektwoche. Der Abend findet in Raum 2 der Schule statt; ein Online-Zugang ist diesmal nicht vorgesehen. Wenn Sie nicht teilnehmen können, schreiben Sie bitte bis Mittwochmittag eine kurze Nachricht an das Sekretariat.',
                    'true_false' => [
                        'no' => 33,
                        'statement' => 'Der Elternabend findet online statt.',
                        'correct' => 'B',
                        'rationale' => 'Im Text steht: in Raum 2 der Schule.',
                    ],
                    'mc' => [
                        'no' => 34,
                        'question' => 'Was sollen Eltern bei Verhinderung tun?',
                        'options' => [
                            'A' => 'Bis Mittwochmittag eine Nachricht senden.',
                            'B' => 'Am Freitag im Sekretariat anrufen.',
                            'C' => 'Ohne Entschuldigung fehlen.',
                        ],
                        'correct' => 'A',
                        'rationale' => 'Die Frist ist Mittwochmittag mit kurzer Nachricht.',
                    ],
                ],
                [
                    'text' => 'Sehr geehrte Kundin, sehr geehrter Kunde, unser Fitnessstudio bietet ab April neue Tarife an. Der Basic-Tarif kostet 24 Euro monatlich und beinhaltet die Nutzung von Montag bis Freitag. Im Plus-Tarif für 34 Euro ist zusätzlich der Wochenendzugang enthalten. Bei Abschluss bis 30. April entfällt die Aufnahmegebühr. Verträge können jeweils zum Monatsende mit einer Frist von vier Wochen gekündigt werden.',
                    'true_false' => [
                        'no' => 35,
                        'statement' => 'Im Basic-Tarif kann man auch am Wochenende trainieren.',
                        'correct' => 'B',
                        'rationale' => 'Wochenende ist nur im Plus-Tarif enthalten.',
                    ],
                    'mc' => [
                        'no' => 36,
                        'question' => 'Was gilt für die Kündigung?',
                        'options' => [
                            'A' => 'Nur zum Jahresende möglich.',
                            'B' => 'Mit vier Wochen Frist zum Monatsende.',
                            'C' => 'Jederzeit ohne Frist.',
                        ],
                        'correct' => 'B',
                        'rationale' => 'Der Text nennt vier Wochen Frist zum Monatsende.',
                    ],
                ],
            ],
        ],
    ];
}

function create_lesen_teil3_structured_set(bool $includeExplanation): array
{
    $pool = build_lesen_teil3_textblock_pools();
    if (!$pool) {
        throw new RuntimeException('Für Lesen Teil 3 sind keine strukturierten Aufgaben verfügbar.');
    }

    $picked = pick_random_item($pool);
    $rawBlocks = array_values((array)($picked['blocks'] ?? []));
    if (count($rawBlocks) < 3) {
        throw new RuntimeException('Für Lesen Teil 3 sind weniger als 3 Textblöcke konfiguriert.');
    }
    $rawBlocks = array_slice($rawBlocks, 0, 3);

    $blocks = [];
    foreach ($rawBlocks as $idx => $block) {
        $blockNo = $idx + 1;
        $tf = (array)($block['true_false'] ?? []);
        $mc = (array)($block['mc'] ?? []);
        $prepared = prepare_training_options(
            (array)($mc['options'] ?? []),
            (string)($mc['correct'] ?? ''),
            true
        );
        $blocks[] = [
            'id' => 'b_' . $blockNo,
            'title' => 'Text ' . $blockNo,
            'text' => germanize_umlauts_text((string)($block['text'] ?? '')),
            'true_false' => [
                'no' => (int)($tf['no'] ?? (31 + ($idx * 2))),
                'statement' => germanize_umlauts_text((string)($tf['statement'] ?? '')),
                'correct' => strtoupper((string)($tf['correct'] ?? 'B')),
                'explanation' => $includeExplanation ? germanize_umlauts_text((string)($tf['rationale'] ?? '')) : '',
            ],
            'mc' => [
                'no' => (int)($mc['no'] ?? (32 + ($idx * 2))),
                'question' => germanize_umlauts_text((string)($mc['question'] ?? 'Welche Antwort passt?')),
                'options' => array_map(static fn($v) => germanize_umlauts_text((string)$v), (array)$prepared['options']),
                'correct' => strtoupper((string)$prepared['correct']),
                'explanation' => $includeExplanation ? germanize_umlauts_text((string)($mc['rationale'] ?? '')) : '',
            ],
        ];
    }

    $item = [
        'set_index' => 1,
        'template_id' => 'lesen_teil3_struct_' . substr(sha1((string)json_encode($picked)), 0, 8),
        'dtz_schema' => 'lesen_teil3_textblock_mix',
        'dtz_part' => 'L3 Drei Texte',
        'task_type' => 'Richtig/Falsch + A/B/C',
        'context' => 'Alltag A2-B1',
        'title' => germanize_umlauts_text((string)($picked['title'] ?? 'Lesen Teil 3')),
        'instructions' => germanize_umlauts_text((string)($picked['instructions'] ?? '')),
        'blocks' => $blocks,
    ];

    return [
        'module' => 'lesen',
        'teil' => 3,
        'count' => 1,
        'include_explanation' => $includeExplanation,
        'generated_at' => gmdate('c'),
        'items' => [$item],
    ];
}

function build_lesen_teil4_richtig_falsch_pools(): array
{
    return [
        [
            'title' => 'Kundendienst: Filmplattform',
            'instructions' => 'Lesen Sie den Text. Entscheiden Sie, ob die Aussagen 37-39 richtig oder falsch sind. Markieren Sie Ihre Lösungen für die Aufgaben 37-39.',
            'text' => 'ViewNow - Mitgliedschaft\n\nBei ViewNow können Sie Filme, Serien und Sportübertragungen live oder später ansehen. Das Angebot ist ab 14 Jahren nutzbar. Jugendliche unter 18 Jahren dürfen den Dienst nur mit Zustimmung der Erziehungsberechtigten nutzen. \n\nEin Basis-Abo kostet 7,99 Euro pro Monat und enthält Werbung. Das Plus-Abo kostet 13,99 Euro und ist ohne Werbung auf zwei Geräten gleichzeitig nutzbar.\n\nDie Zahlung ist jeweils am Tag des Vertragsabschlusses für den nächsten Monat fällig. Geht das Geld nicht rechtzeitig ein, wird der Zugang vorübergehend gesperrt.\n\nDas Abo ist monatlich kündbar. Die Kündigung ist über das Kundenkonto oder per E-Mail möglich.',
            'statements' => [
                [
                    'no' => 37,
                    'statement' => 'Bei ViewNow kann man auch Sportübertragungen sehen.',
                    'correct' => 'A',
                    'rationale' => 'Im Text werden Sportübertragungen ausdrücklich genannt.',
                ],
                [
                    'no' => 38,
                    'statement' => 'Das Plus-Abo kann man auf sechs Geräten gleichzeitig nutzen.',
                    'correct' => 'B',
                    'rationale' => 'Im Text steht: auf zwei Geräten gleichzeitig.',
                ],
                [
                    'no' => 39,
                    'statement' => 'Man kann das Abo auch per E-Mail kündigen.',
                    'correct' => 'A',
                    'rationale' => 'Kündigung über Kundenkonto oder per E-Mail ist möglich.',
                ],
            ],
        ],
        [
            'title' => 'Information vom Wohnungsservice',
            'instructions' => 'Lesen Sie den Text. Entscheiden Sie, ob die Aussagen 37-39 richtig oder falsch sind. Markieren Sie Ihre Lösungen für die Aufgaben 37-39.',
            'text' => 'WohnService Nord - Mieterinformation\n\nUnser Reparaturdienst ist montags bis freitags zwischen 8:00 und 17:00 Uhr erreichbar. Notfälle (zum Beispiel ein Rohrbruch) melden Sie bitte sofort über die Notfallnummer.\n\nFür normale Reparaturen können Sie online einen Termin buchen. Geben Sie dabei bitte Ihre Wohnungsnummer und eine aktuelle Telefonnummer an.\n\nDie jährliche Wartung der Heizungen findet zwischen Oktober und November statt. Sie erhalten den genauen Termin mindestens sieben Tage vorher per E-Mail.\n\nWenn Sie den Termin nicht wahrnehmen können, melden Sie sich bitte spätestens 24 Stunden vorher. Sonst kann eine Servicegebühr von 25 Euro entstehen.',
            'statements' => [
                [
                    'no' => 37,
                    'statement' => 'Notfälle soll man über eine spezielle Nummer melden.',
                    'correct' => 'A',
                    'rationale' => 'Der Text nennt ausdrücklich eine Notfallnummer.',
                ],
                [
                    'no' => 38,
                    'statement' => 'Den Termin für die Heizungswartung bekommt man erst am selben Tag.',
                    'correct' => 'B',
                    'rationale' => 'Der genaue Termin kommt mindestens sieben Tage vorher.',
                ],
                [
                    'no' => 39,
                    'statement' => 'Bei sehr später Absage kann eine Gebühr entstehen.',
                    'correct' => 'A',
                    'rationale' => 'Bei Absage später als 24 Stunden vorher sind 25 Euro möglich.',
                ],
            ],
        ],
        [
            'title' => 'Hinweis vom Integrationskurs',
            'instructions' => 'Lesen Sie den Text. Entscheiden Sie, ob die Aussagen 37-39 richtig oder falsch sind. Markieren Sie Ihre Lösungen für die Aufgaben 37-39.',
            'text' => 'Bildungszentrum am Park - Kursregelung\n\nDer Deutschkurs B1 findet montags, mittwochs und freitags von 9:00 bis 12:15 Uhr statt. Bitte kommen Sie pünktlich, weil wichtige Informationen immer am Anfang gegeben werden.\n\nWenn Sie krank sind, informieren Sie das Sekretariat bis 8:30 Uhr telefonisch oder per E-Mail. Bei längerer Krankheit ab drei Tagen brauchen wir eine ärztliche Bescheinigung.\n\nHausaufgaben werden jeden Freitag im Online-Portal veröffentlicht und sollen bis Montagabend hochgeladen werden. Wer technische Probleme hat, meldet sich bitte direkt bei der Lehrkraft.\n\nFehlende Unterlagen können im Sekretariat zwischen 13:00 und 15:00 Uhr abgegeben werden.',
            'statements' => [
                [
                    'no' => 37,
                    'statement' => 'Der Kurs findet dreimal pro Woche statt.',
                    'correct' => 'A',
                    'rationale' => 'Montag, Mittwoch und Freitag bedeuten drei Kurstage.',
                ],
                [
                    'no' => 38,
                    'statement' => 'Bei Krankheit reicht eine Nachricht bis 10:00 Uhr.',
                    'correct' => 'B',
                    'rationale' => 'Im Text steht als Frist 8:30 Uhr.',
                ],
                [
                    'no' => 39,
                    'statement' => 'Hausaufgaben sollen bis Montagabend im Portal hochgeladen werden.',
                    'correct' => 'A',
                    'rationale' => 'Genau diese Frist wird genannt.',
                ],
            ],
        ],
        [
            'title' => 'Serviceinfo einer Bank',
            'instructions' => 'Lesen Sie den Text. Entscheiden Sie, ob die Aussagen 37-39 richtig oder falsch sind. Markieren Sie Ihre Lösungen für die Aufgaben 37-39.',
            'text' => 'StadtBank - Aktuelle Servicezeiten\n\nUnsere Filiale am Rathausplatz ist montags bis freitags von 9:00 bis 16:00 Uhr geöffnet. Am Donnerstag bieten wir zusätzlich eine Abendberatung bis 18:30 Uhr an.\n\nFür Überweisungen und Kontoauszüge können Sie auch die Automaten im Eingangsbereich nutzen. Dieser Bereich ist täglich von 6:00 bis 22:00 Uhr zugänglich.\n\nWenn Sie eine neue Bankkarte bestellen möchten, können Sie das online im Kundenportal erledigen. Die Karte wird in der Regel innerhalb von fünf Werktagen per Post zugestellt.\n\nBei Verlust der Karte rufen Sie bitte sofort unsere Sperrhotline an, damit keine unberechtigten Zahlungen erfolgen.',
            'statements' => [
                [
                    'no' => 37,
                    'statement' => 'Am Donnerstag gibt es längere Beratungszeiten.',
                    'correct' => 'A',
                    'rationale' => 'Donnerstags ist Beratung bis 18:30 Uhr möglich.',
                ],
                [
                    'no' => 38,
                    'statement' => 'Die Automaten kann man nur während der Filialzeiten benutzen.',
                    'correct' => 'B',
                    'rationale' => 'Der Automatenbereich ist täglich von 6:00 bis 22:00 Uhr offen.',
                ],
                [
                    'no' => 39,
                    'statement' => 'Bei Kartenverlust soll man zuerst auf die neue Karte warten.',
                    'correct' => 'B',
                    'rationale' => 'Man soll sofort die Sperrhotline anrufen.',
                ],
            ],
        ],
    ];
}

function create_lesen_teil4_structured_set(bool $includeExplanation): array
{
    $pool = build_lesen_teil4_richtig_falsch_pools();
    if (!$pool) {
        throw new RuntimeException('Für Lesen Teil 4 sind keine strukturierten Aufgaben verfügbar.');
    }

    $picked = pick_random_item($pool);
    $statements = [];
    foreach (array_values((array)($picked['statements'] ?? [])) as $idx => $row) {
        $no = (int)($row['no'] ?? (37 + $idx));
        $correct = strtoupper((string)($row['correct'] ?? ''));
        if (!in_array($correct, ['A', 'B'], true)) {
            $correct = 'A';
        }
        $statements[] = [
            'id' => 's_' . $no,
            'no' => $no,
            'statement' => germanize_umlauts_text((string)($row['statement'] ?? '')),
            'correct' => $correct,
            'explanation' => $includeExplanation ? germanize_umlauts_text((string)($row['rationale'] ?? '')) : '',
        ];
    }

    $item = [
        'set_index' => 1,
        'template_id' => 'lesen_teil4_struct_' . substr(sha1((string)json_encode($picked)), 0, 8),
        'dtz_schema' => 'lesen_teil4_richtig_falsch_text',
        'dtz_part' => 'L4 Richtig/Falsch',
        'task_type' => 'Textverständnis',
        'context' => 'Alltag A2-B1',
        'title' => germanize_umlauts_text((string)($picked['title'] ?? 'Lesen Teil 4')),
        'instructions' => germanize_umlauts_text((string)($picked['instructions'] ?? '')),
        'text' => germanize_umlauts_text((string)($picked['text'] ?? '')),
        'statements' => $statements,
    ];

    return [
        'module' => 'lesen',
        'teil' => 4,
        'count' => 1,
        'include_explanation' => $includeExplanation,
        'generated_at' => gmdate('c'),
        'items' => [$item],
    ];
}

function build_lesen_teil5_cloze_pools(): array
{
    return [
        [
            'title' => 'Beschwerde an die Hausverwaltung',
            'instructions' => 'Lesen Sie den Text und schliessen Sie die Luecken 40-45. Welche Loesung (A, B oder C) passt am besten?',
            'text_template' => 'Sehr geehrte Damen und Herren,\n\nich wohne seit zwei Jahren in der Gartenstrasse 12 und schreibe Ihnen [0] eines Problems in meiner Wohnung. Seit Montag funktioniert die Heizung nicht mehr, [40] es in den Raeumen sehr kalt ist. Ich habe bereits zweimal im Buero angerufen, [41] leider noch keinen Termin bekommen. Bitte schicken Sie [42] bald einen Techniker. Morgen bin ich von 8 bis 12 Uhr zu Hause, [43] am Nachmittag. Wenn dieser Termin nicht moeglich ist, teilen Sie mir bitte [44] neuen Vorschlag per E-Mail mit. Ich danke Ihnen [45] im Voraus fuer Ihre Rueckmeldung.\n\nMit freundlichen Gruessen\nSema Aydin',
            'example' => [
                'no' => 0,
                'options' => ['A' => 'wegen', 'B' => 'aufgrund', 'C' => 'ueber'],
                'correct' => 'C',
                'rationale' => 'Man schreibt: ueber eines Problems schreiben.',
            ],
            'gaps' => [
                ['no' => 40, 'options' => ['A' => 'obwohl', 'B' => 'weil', 'C' => 'dass'], 'correct' => 'B', 'rationale' => 'Kausalsatz: weil es kalt ist.'],
                ['no' => 41, 'options' => ['A' => 'aber', 'B' => 'oder', 'C' => 'denn'], 'correct' => 'A', 'rationale' => 'Gegensatz: angerufen, aber keinen Termin bekommen.'],
                ['no' => 42, 'options' => ['A' => 'mich', 'B' => 'mir', 'C' => 'ich'], 'correct' => 'B', 'rationale' => 'Dativobjekt: schicken Sie mir einen Techniker.'],
                ['no' => 43, 'options' => ['A' => 'sondern', 'B' => 'und', 'C' => 'oder'], 'correct' => 'A', 'rationale' => 'Nicht ..., sondern ...'],
                ['no' => 44, 'options' => ['A' => 'ein', 'B' => 'einen', 'C' => 'einem'], 'correct' => 'B', 'rationale' => 'Akkusativ maskulin: einen Vorschlag.'],
                ['no' => 45, 'options' => ['A' => 'fuer', 'B' => 'an', 'C' => 'mit'], 'correct' => 'A', 'rationale' => 'Feste Verbindung: ich danke Ihnen im Voraus fuer ...'],
            ],
        ],
        [
            'title' => 'Anfrage wegen Kurswechsel',
            'instructions' => 'Lesen Sie den Text und schliessen Sie die Luecken 40-45. Welche Loesung (A, B oder C) passt am besten?',
            'text_template' => 'Sehr geehrte Frau Keller,\n\nich besuche seit September Ihren Abendkurs. Leider habe ich [0] neue Arbeitszeiten bekommen und kann dienstags nicht mehr teilnehmen. Mein Arbeitgeber hat mir mitgeteilt, [40] ich kuenftig bis 19 Uhr arbeite. Deshalb moechte ich fragen, [41] ein Wechsel in den Vormittagskurs moeglich ist. Ich lerne sehr gern in Ihrer Schule und moechte den Kurs [42] Fall fortsetzen. Falls ein Wechsel nur ab naechstem Monat geht, waere das [43] in Ordnung. Bitte geben Sie mir [44] kurze Rueckmeldung, welche Unterlagen ich dafuer brauche. Vielen Dank [45] Ihre Hilfe.\n\nMit freundlichen Gruessen\nMurat Demir',
            'example' => [
                'no' => 0,
                'options' => ['A' => 'ein', 'B' => 'eine', 'C' => 'einer'],
                'correct' => 'B',
                'rationale' => 'Plural mit Artikel: neue Arbeitszeiten.',
            ],
            'gaps' => [
                ['no' => 40, 'options' => ['A' => 'dass', 'B' => 'ob', 'C' => 'wenn'], 'correct' => 'A', 'rationale' => 'Mitgeteilt, dass ...'],
                ['no' => 41, 'options' => ['A' => 'warum', 'B' => 'ob', 'C' => 'wann'], 'correct' => 'B', 'rationale' => 'Indirekte Frage: ob ein Wechsel moeglich ist.'],
                ['no' => 42, 'options' => ['A' => 'in jedem', 'B' => 'an jedem', 'C' => 'bei jedem'], 'correct' => 'A', 'rationale' => 'Feste Verbindung: in jedem Fall.'],
                ['no' => 43, 'options' => ['A' => 'trotzdem', 'B' => 'noch', 'C' => 'auch'], 'correct' => 'C', 'rationale' => 'Das waere auch in Ordnung.'],
                ['no' => 44, 'options' => ['A' => 'eine', 'B' => 'einen', 'C' => 'einer'], 'correct' => 'A', 'rationale' => 'Akkusativ feminin: eine Rueckmeldung.'],
                ['no' => 45, 'options' => ['A' => 'fuer', 'B' => 'ueber', 'C' => 'ohne'], 'correct' => 'A', 'rationale' => 'Vielen Dank fuer ...'],
            ],
        ],
        [
            'title' => 'Bewerbung um ein Praktikum',
            'instructions' => 'Lesen Sie den Text und schliessen Sie die Luecken 40-45. Welche Loesung (A, B oder C) passt am besten?',
            'text_template' => 'Sehr geehrte Damen und Herren,\n\nhiermit bewerbe ich mich [0] ein zweiwoechiges Praktikum in Ihrem Betrieb. Zurzeit besuche ich einen Deutschkurs auf Niveau B1 und suche eine Moeglichkeit, meine Sprachkenntnisse im Berufsalltag zu verbessern. In Ihrer Anzeige steht, [40] Sie Praktikanten im Bereich Lagerlogistik suchen. Besonders interessiert mich diese Stelle, [41] ich bereits in meinem Heimatland in einem kleinen Lager gearbeitet habe. Ich bin zuverlaessig, puenktlich und lerne schnell. Ab dem 15. Mai bin ich zeitlich flexibel und kann taeglich von 8 bis 16 Uhr arbeiten. Ueber [42] Einladung zu einem Gespraech wuerde ich mich sehr freuen. Meine Unterlagen sende ich Ihnen [43] im Anhang. Fuer Rueckfragen erreichen Sie mich [44] dieser E-Mail-Adresse. Vielen Dank [45] Ihre Zeit.\n\nMit freundlichen Gruessen\nAndrii Petrenko',
            'example' => [
                'no' => 0,
                'options' => ['A' => 'um', 'B' => 'fuer', 'C' => 'bei'],
                'correct' => 'A',
                'rationale' => 'Man bewirbt sich um eine Stelle/ein Praktikum.',
            ],
            'gaps' => [
                ['no' => 40, 'options' => ['A' => 'ob', 'B' => 'dass', 'C' => 'weil'], 'correct' => 'B', 'rationale' => 'In der Anzeige steht, dass ...'],
                ['no' => 41, 'options' => ['A' => 'weil', 'B' => 'damit', 'C' => 'obwohl'], 'correct' => 'A', 'rationale' => 'Begruendung mit weil.'],
                ['no' => 42, 'options' => ['A' => 'den', 'B' => 'dem', 'C' => 'eine'], 'correct' => 'C', 'rationale' => 'Akkusativ feminin: ueber eine Einladung.'],
                ['no' => 43, 'options' => ['A' => 'mit', 'B' => 'bei', 'C' => 'von'], 'correct' => 'A', 'rationale' => 'etwas mit/als Anhang senden.'],
                ['no' => 44, 'options' => ['A' => 'an', 'B' => 'unter', 'C' => 'nach'], 'correct' => 'B', 'rationale' => 'erreichen unter einer Adresse.'],
                ['no' => 45, 'options' => ['A' => 'fuer', 'B' => 'auf', 'C' => 'gegen'], 'correct' => 'A', 'rationale' => 'Vielen Dank fuer Ihre Zeit.'],
            ],
        ],
        [
            'title' => 'E-Mail an ein Hotel',
            'instructions' => 'Lesen Sie den Text und schliessen Sie die Luecken 40-45. Welche Loesung (A, B oder C) passt am besten?',
            'text_template' => 'Sehr geehrte Damen und Herren,\n\nich moechte vom 8. bis 10. Juni ein Doppelzimmer in Ihrem Hotel reservieren und habe dazu [0] Fragen. In Ihrer Online-Anzeige habe ich gelesen, [40] das Fruehstueck im Preis enthalten ist. Koennen Sie mir bitte bestaetigen, ob es auch vegetarische Optionen gibt? Ausserdem reisen wir mit dem Zug an, [41] wir erst gegen 21 Uhr einchecken koennen. Ist ein spaeter Check-in [42] Problem moeglich? Falls Sie fuer die Reservierung eine Anzahlung brauchen, ueberweise ich den Betrag sofort. Bitte schicken Sie mir [43] kurze Bestaetigung mit dem Gesamtpreis. Sie erreichen mich tagsueber [44] Telefon oder per E-Mail. Vielen Dank [45] Ihre schnelle Rueckmeldung.\n\nMit freundlichen Gruessen\nLeyla Sahin',
            'example' => [
                'no' => 0,
                'options' => ['A' => 'keinen', 'B' => 'einige', 'C' => 'jeden'],
                'correct' => 'B',
                'rationale' => 'Ich habe dazu einige Fragen.',
            ],
            'gaps' => [
                ['no' => 40, 'options' => ['A' => 'dass', 'B' => 'ob', 'C' => 'wenn'], 'correct' => 'A', 'rationale' => 'gelesen, dass ...'],
                ['no' => 41, 'options' => ['A' => 'denn', 'B' => 'deshalb', 'C' => 'weil'], 'correct' => 'C', 'rationale' => 'Grund fuer spaeten Check-in: weil ...'],
                ['no' => 42, 'options' => ['A' => 'ohne', 'B' => 'mit', 'C' => 'an'], 'correct' => 'A', 'rationale' => 'ohne Problem moeglich.'],
                ['no' => 43, 'options' => ['A' => 'einen', 'B' => 'eine', 'C' => 'einem'], 'correct' => 'B', 'rationale' => 'Akkusativ feminin: eine Bestaetigung.'],
                ['no' => 44, 'options' => ['A' => 'mit', 'B' => 'bei', 'C' => 'unter'], 'correct' => 'C', 'rationale' => 'unter Telefon erreichbar sein.'],
                ['no' => 45, 'options' => ['A' => 'fuer', 'B' => 'gegen', 'C' => 'ueber'], 'correct' => 'A', 'rationale' => 'Vielen Dank fuer ...'],
            ],
        ],
    ];
}

function create_lesen_teil5_structured_set(bool $includeExplanation): array
{
    $pool = build_lesen_teil5_cloze_pools();
    if (!$pool) {
        throw new RuntimeException('Fuer Lesen Teil 5 sind keine strukturierten Aufgaben verfuegbar.');
    }

    $picked = pick_random_item($pool);
    $gaps = [];
    foreach ((array)($picked['gaps'] ?? []) as $idx => $gap) {
        $prepared = prepare_training_options(
            (array)($gap['options'] ?? []),
            (string)($gap['correct'] ?? ''),
            true
        );
        $no = (int)($gap['no'] ?? (40 + $idx));
        $gaps[] = [
            'id' => 'gap_' . $no,
            'no' => $no,
            'options' => array_map(static fn($v) => germanize_umlauts_text((string)$v), (array)$prepared['options']),
            'correct' => (string)$prepared['correct'],
            'explanation' => $includeExplanation ? germanize_umlauts_text((string)($gap['rationale'] ?? '')) : '',
        ];
    }

    $example = (array)($picked['example'] ?? []);
    $examplePrepared = prepare_training_options(
        (array)($example['options'] ?? []),
        (string)($example['correct'] ?? ''),
        false
    );

    $item = [
        'set_index' => 1,
        'template_id' => 'lesen_teil5_struct_' . substr(sha1((string)json_encode($picked)), 0, 8),
        'dtz_schema' => 'lesen_teil5_lueckentext',
        'dtz_part' => 'L5 Formeller Lueckentext',
        'task_type' => 'Formeller Text mit Luecken',
        'context' => 'Alltag A2-B1',
        'title' => germanize_umlauts_text((string)($picked['title'] ?? 'Lesen Teil 5')),
        'instructions' => germanize_umlauts_text((string)($picked['instructions'] ?? '')),
        'text_template' => germanize_umlauts_text((string)($picked['text_template'] ?? '')),
        'example' => [
            'no' => (int)($example['no'] ?? 0),
            'options' => array_map(static fn($v) => germanize_umlauts_text((string)$v), (array)$examplePrepared['options']),
            'correct' => (string)$examplePrepared['correct'],
            'explanation' => $includeExplanation ? germanize_umlauts_text((string)($example['rationale'] ?? '')) : '',
        ],
        'gaps' => $gaps,
    ];

    return [
        'module' => 'lesen',
        'teil' => 5,
        'count' => 1,
        'include_explanation' => $includeExplanation,
        'generated_at' => gmdate('c'),
        'items' => [$item],
    ];
}

function build_lesen_teil1_wegweiser_pools(): array
{
    return [
        [
            'title' => 'Lesen Teil 1',
            'instructions' => 'Sie sind in einem Einkaufszentrum. Wohin gehen Sie? Lesen Sie die Aufgaben 21-25 und den Wegweiser. Welche Kategorie (a, b oder c) passt am besten?',
            'wegweiser_title' => 'Wegweiser',
            'wegweiser' => [
                'UG: Lebensmittel, Obst & Gemüse, Drogerie',
                'EG: Bäckerei, Apotheke, Geschenkartikel',
                '1. Stock: Damenmode, Kindermode, Schuhe',
                '2. Stock: Elektronik, Handy-Service, Drucker',
                '3. Stock: Friseur, Kosmetik, Sportstudio',
                '4. Stock: Verwaltung, Sprachschule, Büroservice',
            ],
            'situations' => [
                [
                    'no' => 21,
                    'prompt' => 'Sie möchten Ihrer Nachbarin ein Geschenk kaufen. Sie mag Tee.',
                    'options' => ['A' => '2. Stock', 'B' => 'EG', 'C' => 'anderer Stock'],
                    'correct' => 'B',
                    'rationale' => 'Geschenkartikel finden Sie im EG.',
                ],
                [
                    'no' => 22,
                    'prompt' => 'Sie möchten sich die Haare schneiden lassen.',
                    'options' => ['A' => '3. Stock', 'B' => '1. Stock', 'C' => 'anderer Stock'],
                    'correct' => 'A',
                    'rationale' => 'Der Friseur ist im 3. Stock.',
                ],
                [
                    'no' => 23,
                    'prompt' => 'Ihr Sohn braucht neue Turnschuhe für die Schule.',
                    'options' => ['A' => '1. Stock', 'B' => 'UG', 'C' => 'anderer Stock'],
                    'correct' => 'A',
                    'rationale' => 'Schuhe sind im 1. Stock.',
                ],
                [
                    'no' => 24,
                    'prompt' => 'Sie wollen für Ihre Familie frisches Gemüse kaufen.',
                    'options' => ['A' => '4. Stock', 'B' => 'EG', 'C' => 'anderer Stock'],
                    'correct' => 'C',
                    'rationale' => 'Obst und Gemüse gibt es im UG.',
                ],
                [
                    'no' => 25,
                    'prompt' => 'Sie suchen Hosen für Ihre Tochter (11 Monate).',
                    'options' => ['A' => '2. Stock', 'B' => '1. Stock', 'C' => 'anderer Stock'],
                    'correct' => 'B',
                    'rationale' => 'Kindermode ist im 1. Stock.',
                ],
            ],
        ],
        [
            'title' => 'Lesen Teil 1',
            'instructions' => 'Sie sind in einem Kaufhaus. Wohin gehen Sie? Lesen Sie die Aufgaben 21-25 und den Wegweiser. Welche Kategorie (a, b oder c) passt am besten?',
            'wegweiser_title' => 'Wegweiser',
            'wegweiser' => [
                'UG: Supermarkt, Getränke, Tierbedarf',
                'EG: Blumen, Postfiliale, Schlüsseldienst',
                '1. Stock: Kinderabteilung, Schuhe, Spielwaren',
                '2. Stock: Computer, TV, Haushaltsgeräte',
                '3. Stock: Fitness, Tanzschule, Änderungsschneiderei',
                '4. Stock: Reisebüro, Sprachkurse, Verwaltung',
            ],
            'situations' => [
                [
                    'no' => 21,
                    'prompt' => 'Sie müssen ein Paket verschicken.',
                    'options' => ['A' => 'EG', 'B' => '2. Stock', 'C' => 'anderer Stock'],
                    'correct' => 'A',
                    'rationale' => 'Die Postfiliale befindet sich im EG.',
                ],
                [
                    'no' => 22,
                    'prompt' => 'Ihr Sohn möchte ein neues Brettspiel.',
                    'options' => ['A' => 'UG', 'B' => '1. Stock', 'C' => 'anderer Stock'],
                    'correct' => 'B',
                    'rationale' => 'Spielwaren sind im 1. Stock.',
                ],
                [
                    'no' => 23,
                    'prompt' => 'Sie möchten einen neuen Laptop kaufen.',
                    'options' => ['A' => '2. Stock', 'B' => '4. Stock', 'C' => 'anderer Stock'],
                    'correct' => 'A',
                    'rationale' => 'Computer finden Sie im 2. Stock.',
                ],
                [
                    'no' => 24,
                    'prompt' => 'Sie brauchen Futter für Ihren Hund.',
                    'options' => ['A' => 'EG', 'B' => 'UG', 'C' => 'anderer Stock'],
                    'correct' => 'B',
                    'rationale' => 'Tierbedarf gibt es im UG.',
                ],
                [
                    'no' => 25,
                    'prompt' => 'Sie möchten Ihre Hose kürzen lassen.',
                    'options' => ['A' => '3. Stock', 'B' => '1. Stock', 'C' => 'anderer Stock'],
                    'correct' => 'A',
                    'rationale' => 'Die Änderungsschneiderei ist im 3. Stock.',
                ],
            ],
        ],
        [
            'title' => 'Lesen Teil 1',
            'instructions' => 'Sie sind in einem Einkaufszentrum. Wohin gehen Sie? Lesen Sie die Aufgaben 21-25 und den Wegweiser. Welche Kategorie (a, b oder c) passt am besten?',
            'wegweiser_title' => 'Wegweiser',
            'wegweiser' => [
                'UG: Bio-Markt, Getränke, Fahrradservice',
                'EG: Bäckerei, Café, Buchhandlung',
                '1. Stock: Baby- und Kinderkleidung, Schuhe',
                '2. Stock: Mobilfunk, Foto, Elektronik',
                '3. Stock: Friseur, Kosmetik, Nagelstudio',
                '4. Stock: Sprachschule, Nachhilfe, Verwaltung',
            ],
            'situations' => [
                [
                    'no' => 21,
                    'prompt' => 'Sie möchten ein Buch als Geschenk kaufen.',
                    'options' => ['A' => 'EG', 'B' => '2. Stock', 'C' => 'anderer Stock'],
                    'correct' => 'A',
                    'rationale' => 'Die Buchhandlung liegt im EG.',
                ],
                [
                    'no' => 22,
                    'prompt' => 'Ihr Handy-Display ist kaputt.',
                    'options' => ['A' => '2. Stock', 'B' => '3. Stock', 'C' => 'anderer Stock'],
                    'correct' => 'A',
                    'rationale' => 'Mobilfunk finden Sie im 2. Stock.',
                ],
                [
                    'no' => 23,
                    'prompt' => 'Sie suchen Schuhe für Ihr Kleinkind.',
                    'options' => ['A' => '1. Stock', 'B' => 'UG', 'C' => 'anderer Stock'],
                    'correct' => 'A',
                    'rationale' => 'Kinderkleidung und Schuhe sind im 1. Stock.',
                ],
                [
                    'no' => 24,
                    'prompt' => 'Sie möchten Ihre Haare färben lassen.',
                    'options' => ['A' => 'EG', 'B' => '3. Stock', 'C' => 'anderer Stock'],
                    'correct' => 'B',
                    'rationale' => 'Friseur und Kosmetik sind im 3. Stock.',
                ],
                [
                    'no' => 25,
                    'prompt' => 'Sie brauchen einen Deutschkurs am Abend.',
                    'options' => ['A' => '4. Stock', 'B' => '1. Stock', 'C' => 'anderer Stock'],
                    'correct' => 'A',
                    'rationale' => 'Sprachschule ist im 4. Stock.',
                ],
            ],
        ],
    ];
}

function create_lesen_teil1_structured_set(bool $includeExplanation): array
{
    $pool = build_lesen_teil1_wegweiser_pools();
    if (!$pool) {
        throw new RuntimeException('Keine Lesen-Teil-1-Pools verfügbar.');
    }
    $poolCount = count($pool);
    $pickIndex = 0;
    if ($poolCount > 1) {
        try {
            $pickIndex = random_int(0, $poolCount - 1);
        } catch (Throwable $e) {
            $pickIndex = mt_rand(0, $poolCount - 1);
        }
    }
    $picked = (array)$pool[$pickIndex];
    $situationsRaw = array_values((array)($picked['situations'] ?? []));
    $situations = [];
    foreach ($situationsRaw as $idx => $s) {
        $options = prepare_training_options(
            (array)($s['options'] ?? []),
            (string)($s['correct'] ?? ''),
            false
        );
        $situations[] = [
            'id' => 's_' . (21 + $idx),
            'no' => (int)($s['no'] ?? (21 + $idx)),
            'prompt' => germanize_umlauts_text((string)($s['prompt'] ?? '')),
            'options' => array_map(static fn($v) => germanize_umlauts_text((string)$v), (array)$options['options']),
            'correct' => (string)$options['correct'],
            'explanation' => $includeExplanation ? germanize_umlauts_text((string)($s['rationale'] ?? '')) : '',
        ];
    }

    $item = [
        'set_index' => 1,
        'template_id' => 'lesen_teil1_struct_' . substr(sha1((string)json_encode($picked)), 0, 8),
        'dtz_schema' => 'lesen_teil1_wegweiser',
        'dtz_part' => 'L1 Kurznachrichten und Mitteilungen',
        'task_type' => 'Wegweiser',
        'context' => 'Aufgaben 21-25',
        'title' => germanize_umlauts_text((string)($picked['title'] ?? 'Lesen Teil 1')),
        'instructions' => germanize_umlauts_text((string)($picked['instructions'] ?? '')),
        'wegweiser_title' => germanize_umlauts_text((string)($picked['wegweiser_title'] ?? 'Wegweiser')),
        'wegweiser' => array_map(static fn($line) => germanize_umlauts_text((string)$line), (array)($picked['wegweiser'] ?? [])),
        'situations' => $situations,
    ];

    return [
        'module' => 'lesen',
        'teil' => 1,
        'count' => 1,
        'include_explanation' => $includeExplanation,
        'generated_at' => gmdate('c'),
        'items' => [$item],
    ];
}

function build_lesen_teil2_matching_pools(): array
{
    return [
        [
            'title' => 'Lesen Teil 2',
            'instructions' => 'Lesen Sie die Situationen 26-30 und die Anzeigen a-h. Finden Sie für jede Situation die passende Anzeige. Für eine Aufgabe gibt es keine Lösung. Markieren Sie in diesem Fall ein x.',
            'anzeigen' => [
                'A' => 'Haushaltshilfe Senioren | Ort: Nordstadt | Zeit: Mo-Fr 08:00-12:00 | Preis: 18 €/Std | Kontakt: 0157 5511 200',
                'B' => 'Erzieher/in Vollzeit | Ort: Kita Regenbogen | Zeit: ab sofort | Gehalt: TVöD | Kontakt: bewerbung@regenbogen.de',
                'C' => '6 Esszimmerstühle im Set | Ort: Möbelhaus City | Preis: 149 € | Abholung: heute | Kontakt: 040 / 7712 55',
                'D' => 'Englisch-Nachhilfe geben | Ort: Lernpunkt | Zeit: Mo-Fr 18:00-21:00 | Honorar: 22 €/Std | Kontakt: jobs@lernpunkt.de',
                'E' => 'Klapp- und Ausziehtische | Ort: Küchenstudio Platzwunder | Größe: 70-120 cm | Preis: ab 89 € | Kontakt: 040 / 8844 19',
                'F' => 'Deutschkurs B1 intensiv | Ort: Sprachzentrum Mitte | Zeit: täglich 08:00-12:00 | Start: Montag | Kontakt: info@b1kurs.de',
                'G' => 'Büroreinigung nachts | Ort: Gewerbepark West | Zeit: 22:00-02:00 | Minijob | Kontakt: 0176 2200 73',
                'H' => 'Yoga für Jugendliche | Ort: Sportverein Nord | Zeit: Di + Do 17:00 | Beitrag: 15 €/Monat | Kontakt: 040 / 3900 44',
            ],
            'situations' => [
                ['no' => 26, 'prompt' => 'Ihre Mutter ist Rentnerin und braucht Hilfe im Haushalt.', 'correct' => 'A'],
                ['no' => 27, 'prompt' => 'Ein Bekannter ist Erzieher und sucht eine neue Vollzeitstelle.', 'correct' => 'B'],
                ['no' => 28, 'prompt' => 'Sie brauchen für Ihren Tisch im Esszimmer möglichst viele Stühle.', 'correct' => 'C'],
                ['no' => 29, 'prompt' => 'Frau Hilani ist Englischlehrerin und hat abends Zeit, um zu arbeiten.', 'correct' => 'D'],
                ['no' => 30, 'prompt' => 'Ihr Bruder sucht einen Tisch für seine Küche, in der nicht viel Platz ist.', 'correct' => 'E'],
            ],
        ],
        [
            'title' => 'Lesen Teil 2',
            'instructions' => 'Lesen Sie die Situationen 26-30 und die Anzeigen a-h. Finden Sie für jede Situation die passende Anzeige. Für eine Aufgabe gibt es keine Lösung. Markieren Sie in diesem Fall ein x.',
            'anzeigen' => [
                'A' => 'Haushaltshilfe für Rentner | Ort: Altstadt | Zeit: Mo-Fr vormittags | Lohn: 17 €/Std | Kontakt: 0160 3344 888',
                'B' => 'Pädagogische Fachkraft Vollzeit | Ort: Kinderhaus Pusteblume | Start: sofort | Gehalt: nach Tarif | Kontakt: jobs@pusteblume.de',
                'C' => '8 Esszimmerstühle Paket | Ort: Möbel Discount | Preis: 179 € | Lieferung: 2 Tage | Kontakt: 069 / 5544 73',
                'D' => 'Englischkurs am Abend leiten | Ort: Privatschule Delta | Zeit: 19:00-22:00 | Honorar: 24 €/Std | Kontakt: personal@delta.de',
                'E' => 'Schmale Küchentische | Ort: Möbel nach Maß | Maße: ab 60 cm Tiefe | Preis: ab 79 € | Kontakt: 069 / 2200 19',
                'F' => 'Kochkurs für Anfänger | Ort: VHS Süd | Zeit: Sa 10:00-14:00 | Gebühr: 35 € | Kontakt: vhs@stadt.de',
                'G' => 'Verkaufskraft Bäckerei | Ort: Morgenrot | Zeit: ab 05:00 Uhr | Teilzeit | Kontakt: 0173 3321 77',
                'H' => 'Schwimmkurs Kinder 6-8 | Ort: Hallenbad Nord | Zeit: Mi 16:00 | Beitrag: 20 €/Monat | Kontakt: kurs@bad.de',
            ],
            'situations' => [
                ['no' => 26, 'prompt' => 'Ihre Mutter ist Rentnerin und braucht Hilfe im Haushalt.', 'correct' => 'A'],
                ['no' => 27, 'prompt' => 'Ein Bekannter ist Erzieher und sucht eine neue Vollzeitstelle.', 'correct' => 'B'],
                ['no' => 28, 'prompt' => 'Sie brauchen für Ihren Tisch im Esszimmer möglichst viele Stühle.', 'correct' => 'C'],
                ['no' => 29, 'prompt' => 'Frau Hilani ist Englischlehrerin und hat abends Zeit, um zu arbeiten.', 'correct' => 'D'],
                ['no' => 30, 'prompt' => 'Ihr Bruder sucht einen Tisch für seine Küche, in der nicht viel Platz ist.', 'correct' => 'E'],
            ],
        ],
    ];
}

function create_lesen_teil2_structured_set(bool $includeExplanation): array
{
    $pool = build_lesen_teil2_matching_pools();
    if (!$pool) {
        throw new RuntimeException('Keine Lesen-Teil-2-Pools verfügbar.');
    }
    $poolCount = count($pool);
    $pickIndex = 0;
    if ($poolCount > 1) {
        try {
            $pickIndex = random_int(0, $poolCount - 1);
        } catch (Throwable $e) {
            $pickIndex = mt_rand(0, $poolCount - 1);
        }
    }
    $picked = (array)$pool[$pickIndex];
    $anzeigen = [];
    foreach ((array)($picked['anzeigen'] ?? []) as $label => $text) {
        $anzeigen[strtoupper((string)$label)] = germanize_umlauts_text((string)$text);
    }
    $situations = [];
    foreach (array_values((array)($picked['situations'] ?? [])) as $idx => $row) {
        $situations[] = [
            'id' => 's_' . (26 + $idx),
            'no' => (int)($row['no'] ?? (26 + $idx)),
            'prompt' => germanize_umlauts_text((string)($row['prompt'] ?? '')),
            'correct' => strtoupper((string)($row['correct'] ?? 'X')),
            'explanation' => $includeExplanation
                ? germanize_umlauts_text((string)($row['rationale'] ?? ('Passend ist Anzeige ' . strtoupper((string)($row['correct'] ?? 'X')) . '.')))
                : '',
        ];
    }

    $item = [
        'set_index' => 1,
        'template_id' => 'lesen_teil2_struct_' . substr(sha1((string)json_encode($picked)), 0, 8),
        'dtz_schema' => 'lesen_teil2_matching',
        'dtz_part' => 'L2 Situationen und Anzeigen',
        'task_type' => 'Zuordnung',
        'context' => 'Aufgaben 26-30',
        'title' => germanize_umlauts_text((string)($picked['title'] ?? 'Lesen Teil 2')),
        'instructions' => germanize_umlauts_text((string)($picked['instructions'] ?? '')),
        'ads' => $anzeigen,
        'situations' => $situations,
        'labels' => ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'X'],
    ];

    return [
        'module' => 'lesen',
        'teil' => 2,
        'count' => 1,
        'include_explanation' => $includeExplanation,
        'generated_at' => gmdate('c'),
        'items' => [$item],
    ];
}

function create_training_set(string $module, int $count, bool $includeExplanation, int $teil = 0): array
{
    $normalizedModule = normalize_training_module($module);
    if ($normalizedModule === 'hoeren' && $teil >= 1 && $teil <= 4) {
        return create_hoeren_structured_set($teil, $includeExplanation);
    }
    if ($normalizedModule === 'lesen' && $teil === 1) {
        return create_lesen_teil1_structured_set($includeExplanation);
    }
    if ($normalizedModule === 'lesen' && $teil === 2) {
        return create_lesen_teil2_structured_set($includeExplanation);
    }
    if ($normalizedModule === 'lesen' && $teil === 4) {
        return create_lesen_teil4_structured_set($includeExplanation);
    }
    if ($normalizedModule === 'lesen' && $teil === 3) {
        return create_lesen_teil3_structured_set($includeExplanation);
    }
    if ($normalizedModule === 'lesen' && $teil === 5) {
        return create_lesen_teil5_structured_set($includeExplanation);
    }

    $templates = get_training_templates($module, $teil);
    if (!$templates) {
        throw new RuntimeException('Keine gültigen Templates gefunden.');
    }

    $count = clamp_training_count(count($templates), $count);
    if ($count > count($templates)) {
        $count = count($templates);
    }

    $pool = array_values($templates);
    for ($i = count($pool) - 1; $i > 0; $i--) {
        try {
            $j = random_int(0, $i);
        } catch (Throwable $e) {
            $j = mt_rand(0, $i);
        }
        $tmp = $pool[$i];
        $pool[$i] = $pool[$j];
        $pool[$j] = $tmp;
    }
    $picked = array_slice($pool, 0, $count);

    $items = [];
    foreach ($picked as $index => $tpl) {
        $sample = $tpl['sample_item'];
        $prepared = prepare_training_options(
            (array)($sample['options'] ?? []),
            (string)($sample['correct'] ?? ''),
            (bool)($sample['shuffle_options'] ?? true)
        );
        $items[] = [
            'set_index' => $index + 1,
            'template_id' => (string)($tpl['id'] ?? ''),
            'dtz_part' => germanize_umlauts_text((string)($tpl['dtz_part'] ?? '')),
            'task_type' => germanize_umlauts_text((string)($tpl['task_type'] ?? '')),
            'context' => germanize_umlauts_text((string)($tpl['context'] ?? '')),
            'title' => germanize_umlauts_text((string)($tpl['title'] ?? '')),
            'instructions' => germanize_umlauts_text((string)($tpl['instructions'] ?? '')),
            'text' => germanize_umlauts_text((string)($sample['text'] ?? $sample['audio_script'] ?? '')),
            'audio_script' => germanize_umlauts_text((string)($sample['audio_script'] ?? $sample['text'] ?? '')),
            'question' => germanize_umlauts_text((string)($sample['question'] ?? '')),
            'options' => $prepared['options'],
            'correct' => (string)$prepared['correct'],
            'explanation' => $includeExplanation ? germanize_umlauts_text((string)($sample['rationale'] ?? '')) : '',
        ];
        $germanizedOptions = [];
        foreach ((array)$items[$index]['options'] as $label => $optText) {
            $germanizedOptions[(string)$label] = germanize_umlauts_text((string)$optText);
        }
        $items[$index]['options'] = $germanizedOptions;
    }

    return [
        'module' => normalize_training_module($module),
        'teil' => $teil > 0 ? $teil : null,
        'count' => count($items),
        'include_explanation' => $includeExplanation,
        'generated_at' => gmdate('c'),
        'items' => $items,
    ];
}
