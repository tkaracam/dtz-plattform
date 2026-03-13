<?php
declare(strict_types=1);

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
    $value = mb_strtolower(trim($module));
    if ($value === 'lesen') {
        return 'lesen';
    }
    if ($value === 'hoeren' || $value === 'hören') {
        return 'hoeren';
    }
    return '';
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

function get_training_templates(string $module): array
{
    $normalized = normalize_training_module($module);
    if ($normalized === '') {
        throw new RuntimeException('Ungültiges Modul angefordert.');
    }

    if ($normalized === 'hoeren') {
        return build_clean_hoeren_templates();
    }

    $bank = load_training_template_bank();
    $key = 'lesen_templates';
    $templates = $bank[$key] ?? [];
    if (!is_array($templates)) {
        throw new RuntimeException('Template-Liste ist ungültig.');
    }

    $clean = [];
    foreach ($templates as $tpl) {
        if (!is_array($tpl)) {
            continue;
        }
        $sample = $tpl['sample_item'] ?? null;
        $options = is_array($sample['options'] ?? null) ? $sample['options'] : null;
        if (!is_array($sample) || !is_array($options)) {
            continue;
        }
        if (!isset($options['A'], $options['B'], $options['C'])) {
            continue;
        }
        $correct = trim((string)($sample['correct'] ?? ''));
        if (!in_array($correct, ['A', 'B', 'C'], true)) {
            continue;
        }
        $clean[] = $tpl;
    }

    return $clean;
}

function clamp_training_count(string $module, int $count): int
{
    $normalized = normalize_training_module($module);
    $max = $normalized === 'lesen' ? 40 : 30;
    if ($count < 1) {
        $count = 1;
    }
    if ($count > $max) {
        $count = $max;
    }
    return $count;
}

function shuffle_abc_options(array $options, string $correct): array
{
    $pairs = [
        ['label' => 'A', 'text' => (string)($options['A'] ?? '')],
        ['label' => 'B', 'text' => (string)($options['B'] ?? '')],
        ['label' => 'C', 'text' => (string)($options['C'] ?? '')],
    ];

    for ($i = count($pairs) - 1; $i > 0; $i--) {
        try {
            $j = random_int(0, $i);
        } catch (Throwable $e) {
            $j = mt_rand(0, $i);
        }
        $tmp = $pairs[$i];
        $pairs[$i] = $pairs[$j];
        $pairs[$j] = $tmp;
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

function create_training_set(string $module, int $count, bool $includeExplanation): array
{
    $templates = get_training_templates($module);
    if (!$templates) {
        throw new RuntimeException('Keine gültigen Templates gefunden.');
    }

    $count = clamp_training_count($module, $count);
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
        $shuffled = shuffle_abc_options($sample['options'], (string)$sample['correct']);
        $items[] = [
            'set_index' => $index + 1,
            'template_id' => (string)($tpl['id'] ?? ''),
            'dtz_part' => (string)($tpl['dtz_part'] ?? ''),
            'task_type' => (string)($tpl['task_type'] ?? ''),
            'context' => (string)($tpl['context'] ?? ''),
            'title' => (string)($tpl['title'] ?? ''),
            'instructions' => (string)($tpl['instructions'] ?? ''),
            'text' => (string)($sample['text'] ?? $sample['audio_script'] ?? ''),
            'audio_script' => (string)($sample['audio_script'] ?? $sample['text'] ?? ''),
            'question' => (string)($sample['question'] ?? ''),
            'options' => $shuffled['options'],
            'correct' => (string)$shuffled['correct'],
            'explanation' => $includeExplanation ? (string)($sample['rationale'] ?? '') : '',
        ];
    }

    return [
        'module' => normalize_training_module($module),
        'count' => count($items),
        'include_explanation' => $includeExplanation,
        'generated_at' => gmdate('c'),
        'items' => $items,
    ];
}
