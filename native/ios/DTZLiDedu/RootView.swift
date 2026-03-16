import SwiftUI
import AVFoundation

struct RootView: View {
    @StateObject private var session = StudentSessionStore()
    @State private var loading = true

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
            }
        } catch {
            status = "Aufgaben konnten nicht geladen werden"
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
