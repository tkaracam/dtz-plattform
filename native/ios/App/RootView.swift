import SwiftUI
import AVFoundation

struct RootView: View {
    @StateObject private var session = StudentSessionStore()
    @State private var loading = true
    @State private var showOnboarding = !UserDefaults.standard.bool(forKey: "onboarding_seen")

    var body: some View {
        NavigationView {
            if loading {
                ProgressView("Laden...")
                    .onAppear {
                        Task {
                            if let s = try? await APIClient.shared.studentSession() {
                                session.apply(s)
                            }
                            loading = false
                        }
                    }
            } else if showOnboarding {
                OnboardingView(onDone: {
                    UserDefaults.standard.set(true, forKey: "onboarding_seen")
                    showOnboarding = false
                })
            } else {
                if session.authenticated {
                    MainTabView()
                        .environmentObject(session)
                } else {
                    StudentLoginView()
                        .environmentObject(session)
                }
            }
        }
        .navigationViewStyle(StackNavigationViewStyle())
    }
}

struct OnboardingView: View {
    let onDone: () -> Void
    @State private var step = 0

    private let pages = [
        ("DTZ Training", "Hören und Lesen in Teilen üben"),
        ("Schreiben", "Brief schreiben und hochladen"),
        ("Portal", "Korrigierte Briefe im Überblick")
    ]

    var body: some View {
        VStack(spacing: 24) {
            Spacer()
            Text(pages[step].0)
                .font(.largeTitle).bold()
            Text(pages[step].1)
                .font(.headline)
                .foregroundColor(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal)
            Spacer()
            HStack(spacing: 12) {
                Button("Überspringen") { onDone() }
                    .buttonStyle(.bordered)
                Button(step == pages.count - 1 ? "Start" : "Weiter") {
                    if step == pages.count - 1 {
                        onDone()
                    } else {
                        step += 1
                    }
                }
                .buttonStyle(.borderedProminent)
            }
            .padding(.bottom, 24)
        }
        .padding()
    }
}

struct StudentLoginView: View {
    @EnvironmentObject var session: StudentSessionStore
    @State private var username = ""
    @State private var password = ""
    @State private var status = ""

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                Text("DTZ-LID edu")
                    .font(.largeTitle).bold()
                Text("Schüler-Login")
                    .font(.headline)
                TextField("Benutzername", text: $username)
                    .textFieldStyle(.roundedBorder)
                SecureField("Passwort", text: $password)
                    .textFieldStyle(.roundedBorder)
                Button("Anmelden") {
                    Task { await doLogin() }
                }
                .buttonStyle(.borderedProminent)
                if !status.isEmpty { Text(status).foregroundColor(.secondary) }
            }
            .padding()
        }
    }

    private func doLogin() async {
        do {
            let s = try await APIClient.shared.studentLogin(username: username, password: password)
            session.apply(s)
            if !session.authenticated {
                status = "Login fehlgeschlagen"
            }
        } catch {
            status = "Login fehlgeschlagen"
        }
    }
}

struct MainTabView: View {
    @EnvironmentObject var session: StudentSessionStore

    var body: some View {
        TabView {
            HomeView()
                .tabItem { Label("Start", systemImage: "house") }
            DtzTrainingView()
                .tabItem { Label("DTZ", systemImage: "headphones") }
            WritingView()
                .tabItem { Label("Schreiben", systemImage: "pencil") }
            PortalView()
                .tabItem { Label("Portal", systemImage: "checkmark.seal") }
            SettingsView()
                .tabItem { Label("Einstellungen", systemImage: "gear") }
        }
    }
}

struct HomeView: View {
    @EnvironmentObject var session: StudentSessionStore

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                Text("Willkommen")
                    .font(.title2).bold()
                if !session.displayName.isEmpty {
                    Text("Hallo, \(session.displayName)").foregroundColor(.secondary)
                } else {
                    Text("Benutzer: \(session.username)").foregroundColor(.secondary)
                }
                Text("Nutzen Sie DTZ Training und reichen Sie Ihre Aufgaben ein.")
                    .foregroundColor(.secondary)
            }
            .padding()
        }
    }
}

struct DtzTrainingView: View {
    @State private var activeModule: String = "hoeren"

    var body: some View {
        NavigationView {
            List {
                Section(header: Text("DTZ Hören")) {
                    ForEach(1..<5) { teil in
                        NavigationLink("Teil \(teil)") {
                            TrainingDetailView(module: "hoeren", teil: teil)
                        }
                    }
                }
                Section(header: Text("DTZ Lesen")) {
                    ForEach(1..<6) { teil in
                        NavigationLink("Teil \(teil)") {
                            TrainingDetailView(module: "lesen", teil: teil)
                        }
                    }
                }
            }
            .navigationTitle("DTZ Training")
        }
    }
}

struct TrainingDetailView: View {
    let module: String
    let teil: Int

    @State private var item: TrainingItem?
    @State private var status = ""
    @State private var answers: [String: String] = [:]
    @State private var scoreLabel = ""

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                if let item = item {
                    Text(item.title ?? "")
                        .font(.title2).bold()
                    if let instructions = item.instructions { Text(instructions).foregroundColor(.secondary) }
                    renderItem(item)

                    Button("Auswerten") { evaluate(item) }
                        .buttonStyle(.borderedProminent)
                    Button("Neue Aufgaben") { Task { await load() } }
                        .buttonStyle(.bordered)

                    if !scoreLabel.isEmpty {
                        Text(scoreLabel).bold()
                    }
                } else if !status.isEmpty {
                    Text(status).foregroundColor(.secondary)
                } else {
                    ProgressView("Laden...")
                }
            }
            .padding()
        }
        .navigationTitle("\(module.capitalized) Teil \(teil)")
        .onAppear { Task { await load() } }
    }

    private func load() async {
        status = ""
        scoreLabel = ""
        answers = [:]
        do {
            let resp = try await APIClient.shared.trainingSet(module: module, teil: teil)
            if let first = resp.set?.items.first {
                item = first
            } else {
                status = resp.error ?? "Keine Aufgaben verfügbar"
                item = demoTrainingItem(module: module, teil: teil)
                if item != nil { status = "Demo-Modus" }
            }
        } catch {
            status = "Aufgaben konnten nicht geladen werden"
            item = demoTrainingItem(module: module, teil: teil)
            if item != nil { status = "Demo-Modus" }
        }
    }

    @ViewBuilder
    private func renderItem(_ item: TrainingItem) -> some View {
        switch item.dtz_schema {
        case "hoeren_teil1_bundle", "hoeren_teil2_bundle":
            ForEach(item.questions ?? []) { q in
                VStack(alignment: .leading, spacing: 8) {
                    if let audio = q.audio_script { AudioScriptView(text: audio) }
                    Text(q.question ?? "").bold()
                    OptionsView(options: q.options ?? [], key: q.id, answers: $answers)
                }
                .padding(.vertical, 6)
            }
        case "hoeren_teil3_dialogcards":
            ForEach(item.dialogs ?? []) { d in
                VStack(alignment: .leading, spacing: 8) {
                    Text(d.title ?? "Dialog").font(.headline)
                    if let audio = d.audio_script { AudioScriptView(text: audio) }
                    if let tf = d.true_false {
                        Text(tf.statement ?? "").bold()
                        TrueFalseView(key: d.id + "_tf", answers: $answers)
                    }
                    if let detail = d.detail {
                        Text(detail.question ?? "").bold()
                        OptionsView(options: detail.options ?? [], key: d.id + "_mc", answers: $answers)
                    }
                }
                .padding(.vertical, 6)
            }
        case "hoeren_teil4_matching":
            if let options = item.options {
                OptionListView(options: options)
            }
            ForEach(item.statements ?? []) { s in
                VStack(alignment: .leading, spacing: 8) {
                    Text(s.title ?? "Aussage").font(.headline)
                    if let audio = s.audio_script { AudioScriptView(text: audio) }
                    MatchingPickerView(options: item.options ?? [:], key: s.id, answers: $answers)
                }
                .padding(.vertical, 6)
            }
        case "lesen_teil1_wegweiser":
            if let lines = item.wegweiser {
                WegweiserView(title: item.wegweiser_title ?? "Wegweiser", lines: lines)
            }
            ForEach(item.situations ?? []) { s in
                VStack(alignment: .leading, spacing: 8) {
                    Text("\(s.no ?? 0). \(s.prompt ?? "")").bold()
                    OptionsView(options: s.options ?? [], key: s.id, answers: $answers)
                }
                .padding(.vertical, 6)
            }
        case "lesen_teil2_matching":
            if let ads = item.ads {
                AnzeigenView(ads: ads)
            }
            ForEach(item.situations ?? []) { s in
                VStack(alignment: .leading, spacing: 8) {
                    Text("\(s.no ?? 0). \(s.prompt ?? "")").bold()
                    MatchingPickerView(options: adsOrEmpty(item.ads), labels: item.labels ?? [], key: s.id, answers: $answers)
                }
                .padding(.vertical, 6)
            }
        case "lesen_teil3_textblock_mix":
            ForEach(item.blocks ?? []) { block in
                VStack(alignment: .leading, spacing: 8) {
                    Text(block.title ?? "Text").font(.headline)
                    Text(block.text ?? "")
                    if let tf = block.true_false {
                        Text(tf.statement ?? "").bold()
                        TrueFalseView(key: block.id + "_tf", answers: $answers)
                    }
                    if let mc = block.mc {
                        Text(mc.question ?? "").bold()
                        OptionsView(options: mc.options ?? [], key: block.id + "_mc", answers: $answers)
                    }
                }
                .padding(.vertical, 6)
            }
        case "lesen_teil4_richtig_falsch_text":
            if let text = item.text { Text(text) }
            ForEach(item.statements ?? []) { s in
                VStack(alignment: .leading, spacing: 8) {
                    Text("\(s.no ?? 0). \(s.statement ?? "")").bold()
                    TrueFalseView(key: s.id, answers: $answers)
                }
                .padding(.vertical, 6)
            }
        case "lesen_teil5_lueckentext":
            if let template = item.text_template {
                Text(template)
            }
            if let example = item.example {
                Text("Beispiel \(example.no ?? 0)").bold()
                OptionsView(options: example.options ?? [], key: "example", answers: .constant([:]))
            }
            ForEach(item.gaps ?? []) { gap in
                VStack(alignment: .leading, spacing: 8) {
                    Text("Lücke \(gap.no ?? 0)").bold()
                    OptionsView(options: gap.options ?? [], key: gap.id, answers: $answers)
                }
                .padding(.vertical, 6)
            }
        default:
            Text("Aufgabe wird vorbereitet.")
        }
    }

    private func evaluate(_ item: TrainingItem) {
        var total = 0
        var correct = 0

        func check(_ key: String, _ expected: String?) {
            guard let exp = expected else { return }
            total += 1
            if answers[key] == exp { correct += 1 }
        }

        switch item.dtz_schema {
        case "hoeren_teil1_bundle", "hoeren_teil2_bundle":
            for q in item.questions ?? [] {
                check(q.id, q.correct)
            }
        case "hoeren_teil3_dialogcards":
            for d in item.dialogs ?? [] {
                check(d.id + "_tf", mapTf(d.true_false?.correct))
                check(d.id + "_mc", d.detail?.correct)
            }
        case "hoeren_teil4_matching":
            for s in item.statements ?? [] {
                check(s.id, s.correct)
            }
        case "lesen_teil1_wegweiser":
            for s in item.situations ?? [] { check(s.id, s.correct) }
        case "lesen_teil2_matching":
            for s in item.situations ?? [] { check(s.id, s.correct) }
        case "lesen_teil3_textblock_mix":
            for b in item.blocks ?? [] {
                check(b.id + "_tf", mapTf(b.true_false?.correct))
                check(b.id + "_mc", b.mc?.correct)
            }
        case "lesen_teil4_richtig_falsch_text":
            for s in item.statements ?? [] { check(s.id, mapTf(s.correct)) }
        case "lesen_teil5_lueckentext":
            for g in item.gaps ?? [] { check(g.id, g.correct) }
        default: break
        }

        if total > 0 {
            scoreLabel = "Ergebnis: \(correct)/\(total)"
        } else {
            scoreLabel = "Keine Antworten"
        }
    }

    private func mapTf(_ correct: String?) -> String? {
        guard let c = correct else { return nil }
        return c.uppercased() == "A" ? "Richtig" : "Falsch"
    }

    private func adsOrEmpty(_ ads: [String: String]?) -> [String: String] {
        ads ?? [:]
    }
}

private func demoTrainingItem(module: String, teil: Int) -> TrainingItem? {
    if module == "hoeren" {
        if teil == 1 || teil == 2 {
            return TrainingItem(
                template_id: "demo-h1",
                dtz_schema: teil == 1 ? "hoeren_teil1_bundle" : "hoeren_teil2_bundle",
                dtz_part: "H" + String(teil),
                title: "Demo Hören Teil \(teil)",
                instructions: "Hören Sie den Text und wählen Sie die richtige Lösung.",
                audio_script: nil,
                text: nil,
                questions: [
                    TrainingQuestion(rawId: "h\(teil)-q1", question: "Wann beginnt der Kurs?", options: ["Um 8 Uhr", "Um 9 Uhr", "Um 10 Uhr"], correct: "B", audio_script: "Der Kurs beginnt um neun Uhr.", explanation: nil),
                    TrainingQuestion(rawId: "h\(teil)-q2", question: "Wo treffen sich die Teilnehmenden?", options: ["Im Raum 2", "Im Raum 3", "Im Raum 4"], correct: "A", audio_script: "Wir treffen uns im Raum zwei.", explanation: nil),
                    TrainingQuestion(rawId: "h\(teil)-q3", question: "Was sollen Sie mitbringen?", options: ["Einen Ausweis", "Ein Foto", "Ein Formular"], correct: "C", audio_script: "Bitte bringen Sie das Formular mit.", explanation: nil),
                    TrainingQuestion(rawId: "h\(teil)-q4", question: "Wie lange dauert der Termin?", options: ["10 Minuten", "20 Minuten", "30 Minuten"], correct: "B", audio_script: "Der Termin dauert etwa zwanzig Minuten.", explanation: nil)
                ],
                dialogs: nil,
                options: nil,
                statements: nil,
                allow_reuse: nil,
                wegweiser_title: nil,
                wegweiser: nil,
                situations: nil,
                ads: nil,
                labels: nil,
                blocks: nil,
                text_template: nil,
                example: nil,
                gaps: nil
            )
        }
        if teil == 3 {
            return TrainingItem(
                template_id: "demo-h3",
                dtz_schema: "hoeren_teil3_dialogcards",
                dtz_part: "H3",
                title: "Demo Hören Teil 3",
                instructions: "Hören Sie die Dialoge.",
                audio_script: nil,
                text: nil,
                questions: nil,
                dialogs: [
                    TrainingDialog(rawId: "h3-d1", title: "Dialog 1", audio_script: "A: Hast du morgen Zeit? B: Ja, am Nachmittag.", true_false: TrainingTrueFalse(statement: "Sie haben morgen Nachmittag Zeit.", correct: "A", explanation: nil), detail: TrainingDetailQuestion(question: "Wann passt es?", options: ["Morgens", "Nachmittags", "Abends"], correct: "B", explanation: nil)),
                    TrainingDialog(rawId: "h3-d2", title: "Dialog 2", audio_script: "A: Kannst du heute kommen? B: Leider nicht, ich arbeite bis sechs.", true_false: TrainingTrueFalse(statement: "Die Person arbeitet bis 18 Uhr.", correct: "A", explanation: nil), detail: TrainingDetailQuestion(question: "Warum kann sie nicht kommen?", options: ["Krankheit", "Arbeit", "Urlaub"], correct: "B", explanation: nil)),
                    TrainingDialog(rawId: "h3-d3", title: "Dialog 3", audio_script: "A: Wir treffen uns um halb zwei, richtig? B: Ja, um 13:30 Uhr.", true_false: TrainingTrueFalse(statement: "Das Treffen ist um 13:30 Uhr.", correct: "A", explanation: nil), detail: TrainingDetailQuestion(question: "Wann ist das Treffen?", options: ["12:30", "13:30", "14:30"], correct: "B", explanation: nil))
                ],
                options: nil,
                statements: nil,
                allow_reuse: nil,
                wegweiser_title: nil,
                wegweiser: nil,
                situations: nil,
                ads: nil,
                labels: nil,
                blocks: nil,
                text_template: nil,
                example: nil,
                gaps: nil
            )
        }
        if teil == 4 {
            return TrainingItem(
                template_id: "demo-h4",
                dtz_schema: "hoeren_teil4_matching",
                dtz_part: "H4",
                title: "Demo Hören Teil 4",
                instructions: "Ordnen Sie die Aussagen zu.",
                audio_script: nil,
                text: nil,
                questions: nil,
                dialogs: nil,
                options: ["A": "Einladung", "B": "Termin absagen", "C": "Information"],
                statements: [
                    TrainingStatement(rawId: "h4-s1", title: "Aussage 1", statement: nil, question: nil, audio_script: "Der Termin morgen muss leider verschoben werden.", correct: "B", explanation: nil, no: 1),
                    TrainingStatement(rawId: "h4-s2", title: "Aussage 2", statement: nil, question: nil, audio_script: "Sie sind herzlich zur Feier eingeladen.", correct: "A", explanation: nil, no: 2),
                    TrainingStatement(rawId: "h4-s3", title: "Aussage 3", statement: nil, question: nil, audio_script: "Der Kurs startet am Montag um neun Uhr.", correct: "C", explanation: nil, no: 3),
                    TrainingStatement(rawId: "h4-s4", title: "Aussage 4", statement: nil, question: nil, audio_script: "Bitte beachten Sie die neuen Öffnungszeiten.", correct: "C", explanation: nil, no: 4),
                    TrainingStatement(rawId: "h4-s5", title: "Aussage 5", statement: nil, question: nil, audio_script: "Die Anmeldung ist heute bis 16 Uhr möglich.", correct: "C", explanation: nil, no: 5)
                ],
                allow_reuse: nil,
                wegweiser_title: nil,
                wegweiser: nil,
                situations: nil,
                ads: nil,
                labels: ["A", "B", "C"],
                blocks: nil,
                text_template: nil,
                example: nil,
                gaps: nil
            )
        }
    }

    if module == "lesen" {
        if teil == 1 {
            return TrainingItem(
                template_id: "demo-l1",
                dtz_schema: "lesen_teil1_wegweiser",
                dtz_part: "L1",
                title: "Demo Lesen Teil 1",
                instructions: "Wählen Sie die richtige Stelle.",
                audio_script: nil,
                text: nil,
                questions: nil,
                dialogs: nil,
                options: nil,
                statements: nil,
                allow_reuse: nil,
                wegweiser_title: "Wegweiser",
                wegweiser: ["EG: Anmeldung, Information", "1. OG: Kursräume 1–3", "2. OG: Bibliothek"],
                situations: [
                    TrainingSituation(rawId: "l1-s1", no: 1, prompt: "Sie möchten sich anmelden.", options: ["EG", "1. OG", "2. OG"], correct: "A", explanation: nil),
                    TrainingSituation(rawId: "l1-s2", no: 2, prompt: "Sie suchen die Bibliothek.", options: ["EG", "1. OG", "2. OG"], correct: "C", explanation: nil),
                    TrainingSituation(rawId: "l1-s3", no: 3, prompt: "Sie brauchen Raum 2.", options: ["EG", "1. OG", "2. OG"], correct: "B", explanation: nil),
                    TrainingSituation(rawId: "l1-s4", no: 4, prompt: "Sie möchten Informationen.", options: ["EG", "1. OG", "2. OG"], correct: "A", explanation: nil),
                    TrainingSituation(rawId: "l1-s5", no: 5, prompt: "Sie suchen Kursraum 3.", options: ["EG", "1. OG", "2. OG"], correct: "B", explanation: nil)
                ],
                ads: nil,
                labels: nil,
                blocks: nil,
                text_template: nil,
                example: nil,
                gaps: nil
            )
        }
        if teil == 2 {
            return TrainingItem(
                template_id: "demo-l2",
                dtz_schema: "lesen_teil2_matching",
                dtz_part: "L2",
                title: "Demo Lesen Teil 2",
                instructions: "Ordnen Sie die Anzeigen zu.",
                audio_script: nil,
                text: nil,
                questions: nil,
                dialogs: nil,
                options: nil,
                statements: nil,
                allow_reuse: nil,
                wegweiser_title: nil,
                wegweiser: nil,
                situations: [
                    TrainingSituation(rawId: "l2-s1", no: 1, prompt: "Sie suchen eine Wohnung.", options: nil, correct: "A", explanation: nil),
                    TrainingSituation(rawId: "l2-s2", no: 2, prompt: "Sie brauchen einen Sprachkurs.", options: nil, correct: "B", explanation: nil),
                    TrainingSituation(rawId: "l2-s3", no: 3, prompt: "Sie möchten ein Fahrrad kaufen.", options: nil, correct: "C", explanation: nil),
                    TrainingSituation(rawId: "l2-s4", no: 4, prompt: "Sie suchen einen Job.", options: nil, correct: "D", explanation: nil),
                    TrainingSituation(rawId: "l2-s5", no: 5, prompt: "Sie brauchen einen Babysitter.", options: nil, correct: "E", explanation: nil)
                ],
                ads: ["A": "2-Zimmer-Wohnung, zentral", "B": "Deutschkurse am Abend", "C": "Fahrrad zu verkaufen", "D": "Minijob im Café", "E": "Babysitter gesucht"],
                labels: ["A", "B", "C", "D", "E"],
                blocks: nil,
                text_template: nil,
                example: nil,
                gaps: nil
            )
        }
        if teil == 3 {
            return TrainingItem(
                template_id: "demo-l3",
                dtz_schema: "lesen_teil3_textblock_mix",
                dtz_part: "L3",
                title: "Demo Lesen Teil 3",
                instructions: "Lesen Sie die Texte und beantworten Sie die Fragen.",
                audio_script: nil,
                text: nil,
                questions: nil,
                dialogs: nil,
                options: nil,
                statements: nil,
                allow_reuse: nil,
                wegweiser_title: nil,
                wegweiser: nil,
                situations: nil,
                ads: nil,
                labels: nil,
                blocks: [
                    TrainingBlock(rawId: "l3-b1", title: "Infoabend", text: "Der Infoabend findet am Dienstag um 18 Uhr statt.", true_false: TrainingBlockTrueFalse(no: 31, statement: "Der Infoabend ist am Dienstag.", correct: "A", explanation: nil), mc: TrainingBlockMC(no: 32, question: "Wann beginnt der Infoabend?", options: ["18 Uhr", "19 Uhr", "20 Uhr"], correct: "A", explanation: nil)),
                    TrainingBlock(rawId: "l3-b2", title: "Bibliothek", text: "Die Bibliothek ist am Freitag geschlossen.", true_false: TrainingBlockTrueFalse(no: 33, statement: "Am Freitag ist die Bibliothek geschlossen.", correct: "A", explanation: nil), mc: TrainingBlockMC(no: 34, question: "Wann ist geschlossen?", options: ["Freitag", "Samstag", "Sonntag"], correct: "A", explanation: nil)),
                    TrainingBlock(rawId: "l3-b3", title: "Kurswechsel", text: "Der Kurs beginnt nächsten Montag um 9 Uhr.", true_false: TrainingBlockTrueFalse(no: 35, statement: "Der Kurs beginnt am Montag.", correct: "A", explanation: nil), mc: TrainingBlockMC(no: 36, question: "Wann beginnt der Kurs?", options: ["Montag", "Dienstag", "Mittwoch"], correct: "A", explanation: nil))
                ],
                text_template: nil,
                example: nil,
                gaps: nil
            )
        }
        if teil == 4 {
            return TrainingItem(
                template_id: "demo-l4",
                dtz_schema: "lesen_teil4_richtig_falsch_text",
                dtz_part: "L4",
                title: "Demo Lesen Teil 4",
                instructions: "Lesen Sie den Text und entscheiden Sie.",
                audio_script: nil,
                text: "Die Bibliothek ist montags bis freitags von 9 bis 18 Uhr geöffnet.",
                questions: nil,
                dialogs: nil,
                options: nil,
                statements: [
                    TrainingStatement(rawId: "l4-s1", title: nil, statement: "Am Samstag ist die Bibliothek geöffnet.", question: nil, audio_script: nil, correct: "B", explanation: nil, no: 37),
                    TrainingStatement(rawId: "l4-s2", title: nil, statement: "Die Bibliothek schließt um 18 Uhr.", question: nil, audio_script: nil, correct: "A", explanation: nil, no: 38),
                    TrainingStatement(rawId: "l4-s3", title: nil, statement: "Die Bibliothek öffnet um 9 Uhr.", question: nil, audio_script: nil, correct: "A", explanation: nil, no: 39),
                    TrainingStatement(rawId: "l4-s4", title: nil, statement: "Die Bibliothek ist sonntags geöffnet.", question: nil, audio_script: nil, correct: "B", explanation: nil, no: 40)
                ],
                allow_reuse: nil,
                wegweiser_title: nil,
                wegweiser: nil,
                situations: nil,
                ads: nil,
                labels: nil,
                blocks: nil,
                text_template: nil,
                example: nil,
                gaps: nil
            )
        }
        if teil == 5 {
            return TrainingItem(
                template_id: "demo-l5",
                dtz_schema: "lesen_teil5_lueckentext",
                dtz_part: "L5",
                title: "Demo Lesen Teil 5",
                instructions: "Schließen Sie die Lücken.",
                audio_script: nil,
                text: nil,
                questions: nil,
                dialogs: nil,
                options: nil,
                statements: nil,
                allow_reuse: nil,
                wegweiser_title: nil,
                wegweiser: nil,
                situations: nil,
                ads: nil,
                labels: nil,
                blocks: nil,
                text_template: "Sehr geehrte Damen und Herren, ich möchte \_\_\_ einen Termin vereinbaren.",
                example: TrainingClozeExample(no: 0, options: ["gern", "gerne", "gernem"], correct: "B", explanation: nil),
                gaps: [
                    TrainingGap(rawId: "l5-g1", no: 40, options: ["für", "zu", "an"], correct: "A", explanation: nil),
                    TrainingGap(rawId: "l5-g2", no: 41, options: ["am", "im", "auf"], correct: "B", explanation: nil),
                    TrainingGap(rawId: "l5-g3", no: 42, options: ["bitte", "bittet", "gebeten"], correct: "A", explanation: nil),
                    TrainingGap(rawId: "l5-g4", no: 43, options: ["seit", "vor", "bei"], correct: "A", explanation: nil),
                    TrainingGap(rawId: "l5-g5", no: 44, options: ["einen", "einem", "einer"], correct: "A", explanation: nil),
                    TrainingGap(rawId: "l5-g6", no: 45, options: ["wenn", "weil", "dass"], correct: "A", explanation: nil)
                ]
            )
        }
    }

    return nil
}

struct OptionsView: View {
    let options: [String]
    let key: String
    @Binding var answers: [String: String]

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            ForEach(Array(options.enumerated()), id: \ .offset) { idx, option in
                let label = ["A", "B", "C"][safe: idx] ?? ""
                HStack {
                    Image(systemName: answers[key] == label ? "largecircle.fill.circle" : "circle")
                    Text("\(label)) \(option)")
                }
                .onTapGesture { answers[key] = label }
            }
        }
    }
}

struct TrueFalseView: View {
    let key: String
    @Binding var answers: [String: String]

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Image(systemName: answers[key] == "Richtig" ? "largecircle.fill.circle" : "circle")
                Text("Richtig")
            }.onTapGesture { answers[key] = "Richtig" }
            HStack {
                Image(systemName: answers[key] == "Falsch" ? "largecircle.fill.circle" : "circle")
                Text("Falsch")
            }.onTapGesture { answers[key] = "Falsch" }
        }
    }
}

struct MatchingPickerView: View {
    let options: [String: String]
    var labels: [String] = []
    let key: String
    @Binding var answers: [String: String]

    var body: some View {
        let list = labels.isEmpty ? options.keys.sorted() : labels
        Picker("Auswahl", selection: Binding(
            get: { answers[key] ?? "" },
            set: { answers[key] = $0 }
        )) {
            Text("Bitte wählen").tag("")
            ForEach(list, id: \ .self) { label in
                Text(label).tag(label)
            }
        }
        .pickerStyle(.menu)
    }
}

struct WegweiserView: View {
    let title: String
    let lines: [String]

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title).font(.headline)
            ForEach(lines, id: \ .self) { line in
                Text(line)
            }
        }
        .padding()
        .background(Color(.secondarySystemBackground))
        .cornerRadius(10)
    }
}

struct AnzeigenView: View {
    let ads: [String: String]

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            ForEach(ads.keys.sorted(), id: \ .self) { key in
                Text("\(key): \(ads[key] ?? "")")
            }
        }
        .padding()
        .background(Color(.secondarySystemBackground))
        .cornerRadius(10)
    }
}

struct OptionListView: View {
    let options: [String: String]

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            ForEach(options.keys.sorted(), id: \ .self) { key in
                Text("\(key): \(options[key] ?? "")")
            }
        }
        .padding()
        .background(Color(.secondarySystemBackground))
        .cornerRadius(10)
    }
}

struct AudioScriptView: View {
    let text: String
    @State private var speaking = false
    private let synthesizer = AVSpeechSynthesizer()

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(text)
                .font(.callout)
            Button(action: speak) {
                Text(speaking ? "Stopp" : "Audio abspielen")
            }
        }
    }

    private func speak() {
        if speaking {
            synthesizer.stopSpeaking(at: .immediate)
            speaking = false
            return
        }
        let utterance = AVSpeechUtterance(string: text)
        utterance.voice = AVSpeechSynthesisVoice(language: "de-DE")
        synthesizer.speak(utterance)
        speaking = true
    }
}

struct WritingView: View {
    @State private var homework: HomeworkCurrentResponse?
    @State private var status = ""
    @State private var letterText = ""
    @State private var startedAt = ""
    @State private var durationSeconds = 0

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                Text("Mail schreiben")
                    .font(.title2).bold()
                if let hw = homework, hw.has_assignment == true, let assignment = hw.assignment {
                    Text(assignment.title ?? "Aufgabe")
                        .font(.headline)
                    Text(assignment.description ?? "")
                        .foregroundColor(.secondary)
                    if let state = hw.state {
                        Text("Status: \(state.locked == true ? "gesperrt" : "aktiv")")
                        if let remaining = state.remaining_seconds {
                            Text("Restzeit: \(remaining) сек")
                        }
                    }
                    if hw.state?.can_start == true {
                        Button("Bearbeitung starten") { Task { await startAssignment() } }
                            .buttonStyle(.bordered)
                    }
                    TextEditor(text: $letterText)
                        .frame(height: 200)
                        .overlay(RoundedRectangle(cornerRadius: 8).stroke(Color.gray.opacity(0.2)))
                    Button("Brief hochladen") { Task { await submit() } }
                        .buttonStyle(.borderedProminent)
                } else {
                    Text(hwMessage())
                        .foregroundColor(.secondary)
                }
                if !status.isEmpty { Text(status).foregroundColor(.secondary) }
            }
            .padding()
        }
        .onAppear { Task { await load() } }
    }

    private func hwMessage() -> String {
        homework?.message ?? "Derzeit keine Aufgabe."
    }

    private func load() async {
        do {
            homework = try await APIClient.shared.currentHomework()
        } catch {
            status = "Aufgabe konnte nicht geladen werden"
        }
    }

    private func startAssignment() async {
        guard let id = homework?.assignment?.id else { return }
        do {
            let resp = try await APIClient.shared.startHomework(assignmentId: id)
            startedAt = resp.started_at ?? ""
            durationSeconds = resp.remaining_seconds ?? 0
            await load()
        } catch {
            status = "Start fehlgeschlagen"
        }
    }

    private func submit() async {
        guard let assignment = homework?.assignment else { return }
        do {
            _ = try await APIClient.shared.submitLetter(
                assignmentId: assignment.id ?? "",
                prompt: assignment.description ?? "",
                text: letterText,
                startedAt: startedAt,
                durationSeconds: durationSeconds
            )
            status = "Brief hochgeladen"
            letterText = ""
            await load()
        } catch {
            status = "Upload fehlgeschlagen"
        }
    }
}

struct PortalView: View {
    @State private var portal: StudentPortalResponse?
    @State private var status = ""

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                Text("Korrigierte Briefe")
                    .font(.title2).bold()
                if let corrections = portal?.letter_corrections, !corrections.isEmpty {
                    ForEach(corrections) { c in
                        VStack(alignment: .leading, spacing: 6) {
                            Text(c.topic ?? "Brief")
                                .font(.headline)
                            if let score = c.score_total {
                                Text("Punkte: \(score)/20")
                            }
                            Text(c.corrected_text ?? "")
                                .foregroundColor(.secondary)
                        }
                        .padding()
                        .background(Color(.secondarySystemBackground))
                        .cornerRadius(10)
                    }
                } else {
                    Text("Noch keine freigegebenen Korrekturen")
                        .foregroundColor(.secondary)
                }

                Text("Ergebnisse")
                    .font(.title3).bold()
                if let results = portal?.results {
                    ForEach(results) { r in
                        VStack(alignment: .leading) {
                            Text(r.type ?? "")
                            Text(r.detail ?? "").foregroundColor(.secondary)
                        }
                        .padding(.vertical, 4)
                    }
                }

                if !status.isEmpty { Text(status).foregroundColor(.secondary) }
            }
            .padding()
        }
        .onAppear { Task { await load() } }
    }

    private func load() async {
        do {
            portal = try await APIClient.shared.studentPortal()
        } catch {
            status = "Portal konnte nicht geladen werden"
        }
    }
}

struct SettingsView: View {
    @EnvironmentObject var session: StudentSessionStore
    @State private var status = ""

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Abmelden")
                .font(.headline)
            Button("Abmelden") { Task { await logout() } }
                .buttonStyle(.bordered)
            if !status.isEmpty { Text(status).foregroundColor(.secondary) }
        }
        .padding()
    }

    private func logout() async {
        _ = try? await APIClient.shared.studentLogout()
        session.clear()
    }
}

extension Array {
    subscript(safe index: Int) -> Element? {
        guard indices.contains(index) else { return nil }
        return self[index]
    }
}
