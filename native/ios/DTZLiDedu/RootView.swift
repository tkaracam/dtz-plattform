import SwiftUI
import AVFoundation

private enum AppTab: Hashable {
    case home
    case dtz
    case sprechen
    case lid
    case schreiben
    case portal
    case settings
}

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
                OnboardingView {
                    UserDefaults.standard.set(true, forKey: "onboarding_seen")
                    showOnboarding = false
                }
            } else if session.authenticated {
                MainTabView()
                    .environmentObject(session)
            } else {
                StudentLoginView()
                    .environmentObject(session)
            }
        }
        .navigationViewStyle(StackNavigationViewStyle())
    }
}

struct OnboardingView: View {
    let onDone: () -> Void
    @State private var step = 0

    private let pages = [
        ("DTZ Training", "Hören und Lesen in Teilen üben."),
        ("Sprechen und LiD", "Mündlich üben und LiD-Fragen direkt in der App lösen."),
        ("Portal und Schreiben", "Hausaufgaben sehen, Mail schreiben und Korrekturen prüfen.")
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
                Button("Überspringen", action: onDone)
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
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                SecureField("Passwort", text: $password)
                    .textFieldStyle(.roundedBorder)
                Button("Anmelden") {
                    Task { await doLogin() }
                }
                .buttonStyle(.borderedProminent)
                if !status.isEmpty {
                    Text(status)
                        .foregroundColor(.secondary)
                }
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
    @State private var selectedTab: AppTab = .home

    var body: some View {
        TabView(selection: $selectedTab) {
            HomeView(selectedTab: $selectedTab)
                .tabItem { Label("Start", systemImage: "house") }
                .tag(AppTab.home)
            DtzTrainingView()
                .tabItem { Label("DTZ", systemImage: "headphones") }
                .tag(AppTab.dtz)
            SpeakingPracticeView()
                .tabItem { Label("Sprechen", systemImage: "waveform") }
                .tag(AppTab.sprechen)
            LidPracticeView()
                .tabItem { Label("LiD", systemImage: "building.columns") }
                .tag(AppTab.lid)
            WritingView()
                .tabItem { Label("Schreiben", systemImage: "square.and.pencil") }
                .tag(AppTab.schreiben)
            PortalView()
                .tabItem { Label("Portal", systemImage: "checkmark.seal") }
                .tag(AppTab.portal)
            SettingsView()
                .tabItem { Label("Einstellungen", systemImage: "gearshape") }
                .tag(AppTab.settings)
        }
    }
}

struct HomeView: View {
    @EnvironmentObject var session: StudentSessionStore
    @Binding var selectedTab: AppTab

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                Text("Willkommen")
                    .font(.title2).bold()
                Text(session.displayName.isEmpty ? "Benutzer: \(session.username)" : "Hallo, \(session.displayName)")
                    .foregroundColor(.secondary)

                quickCard(
                    title: "DTZ Training",
                    subtitle: "Hören und Lesen in Teilen üben",
                    systemImage: "headphones",
                    color: .orange
                ) { selectedTab = .dtz }

                quickCard(
                    title: "Sprechen",
                    subtitle: "Teil 1 bis 3 direkt mündlich üben",
                    systemImage: "waveform",
                    color: .pink
                ) { selectedTab = .sprechen }

                quickCard(
                    title: "Leben in Deutschland",
                    subtitle: "33 Fragen lösen und Ergebnis prüfen",
                    systemImage: "building.columns",
                    color: .blue
                ) { selectedTab = .lid }

                quickCard(
                    title: "Portal",
                    subtitle: "Hausaufgaben und Korrekturen ansehen",
                    systemImage: "checkmark.seal",
                    color: .green
                ) { selectedTab = .portal }
            }
            .padding()
        }
    }

    private func quickCard(title: String, subtitle: String, systemImage: String, color: Color, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 14) {
                Image(systemName: systemImage)
                    .font(.title2.weight(.semibold))
                    .frame(width: 46, height: 46)
                    .background(color.opacity(0.14))
                    .foregroundColor(color)
                    .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
                VStack(alignment: .leading, spacing: 4) {
                    Text(title)
                        .font(.headline)
                        .foregroundColor(.primary)
                    Text(subtitle)
                        .font(.subheadline)
                        .foregroundColor(.secondary)
                }
                Spacer()
                Image(systemName: "chevron.right")
                    .foregroundColor(.secondary)
            }
            .padding()
            .background(Color(.secondarySystemBackground))
            .clipShape(RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

struct DtzTrainingView: View {
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
                    if let instructions = item.instructions {
                        Text(instructions).foregroundColor(.secondary)
                    }
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
                    MatchingPickerView(options: item.ads ?? [:], labels: item.labels ?? [], key: s.id, answers: $answers)
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
            if let template = item.text_template { Text(template) }
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
            for q in item.questions ?? [] { check(q.id, q.correct) }
        case "hoeren_teil3_dialogcards":
            for d in item.dialogs ?? [] {
                check(d.id + "_tf", mapTf(d.true_false?.correct))
                check(d.id + "_mc", d.detail?.correct)
            }
        case "hoeren_teil4_matching":
            for s in item.statements ?? [] { check(s.id, s.correct) }
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
        default:
            break
        }

        scoreLabel = total > 0 ? "Ergebnis: \(correct)/\(total)" : "Keine Antworten"
    }

    private func mapTf(_ correct: String?) -> String? {
        guard let c = correct else { return nil }
        return c.uppercased() == "A" ? "Richtig" : "Falsch"
    }
}

struct SpeakingPracticeView: View {
    @State private var part2 = speakingPart2Topics.randomElement() ?? speakingPart2Topics[0]
    @State private var part3 = speakingPart3Topics.randomElement() ?? speakingPart3Topics[0]

    var body: some View {
        NavigationView {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    GroupBox("Teil 1: Sich vorstellen") {
                        VStack(alignment: .leading, spacing: 8) {
                            Text("Stellen Sie sich vor. Teil 1 bleibt immer gleich.")
                                .foregroundColor(.secondary)
                            ForEach(["Name", "Alter", "Land", "Wohnort", "Sprachen", "Beruf"], id: \.self) { point in
                                Label(point, systemImage: "checkmark.circle")
                            }
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                    }

                    GroupBox("Teil 2: Bildbeschreibung") {
                        VStack(alignment: .leading, spacing: 12) {
                            SpeakingImageCard(scene: part2.scene, title: part2.title)
                            Text(part2.lead)
                                .foregroundColor(.secondary)
                            ForEach(part2.points, id: \.self) { point in
                                Label(point, systemImage: "bubble.left.and.bubble.right")
                            }
                            Button("Neues Bild") {
                                part2 = speakingPart2Topics.randomElement() ?? part2
                            }
                            .buttonStyle(.borderedProminent)
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                    }

                    GroupBox("Teil 3: Gemeinsam etwas planen") {
                        VStack(alignment: .leading, spacing: 12) {
                            Text("Sie möchten \(part3.task).")
                                .font(.headline)
                            Text("Planen Sie gemeinsam \(part3.plan).")
                                .foregroundColor(.secondary)
                            ForEach(part3.points, id: \.self) { point in
                                Label(point, systemImage: "list.bullet")
                            }
                            Button("Neue Planungsaufgabe") {
                                part3 = speakingPart3Topics.randomElement() ?? part3
                            }
                            .buttonStyle(.borderedProminent)
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                    }
                }
                .padding()
            }
            .navigationTitle("Sprechen")
        }
    }
}

struct SpeakingImageCard: View {
    let scene: String
    let title: String

    var body: some View {
        ZStack(alignment: .bottomLeading) {
            RoundedRectangle(cornerRadius: 20, style: .continuous)
                .fill(
                    LinearGradient(
                        colors: [Color.orange.opacity(0.85), Color.pink.opacity(0.75)],
                        startPoint: .topLeading,
                        endPoint: .bottomTrailing
                    )
                )
                .aspectRatio(16 / 9, contentMode: .fit)
            VStack(alignment: .leading, spacing: 8) {
                Spacer()
                Image(systemName: sceneSymbol(scene))
                    .font(.system(size: 54, weight: .semibold))
                    .foregroundColor(.white.opacity(0.94))
                Text(title)
                    .font(.headline)
                    .foregroundColor(.white)
            }
            .padding(18)
        }
    }

    private func sceneSymbol(_ scene: String) -> String {
        switch scene {
        case "supermarkt": return "cart"
        case "arzt": return "cross.case"
        case "kurs": return "text.book.closed"
        case "bahnhof": return "tram"
        case "familie": return "house.lodge"
        case "bibliothek": return "books.vertical"
        case "restaurant": return "fork.knife"
        case "buero": return "desktopcomputer"
        case "spielplatz": return "figure.and.child.holdinghands"
        case "park": return "leaf"
        case "markt": return "basket"
        case "sport": return "figure.run"
        default: return "photo"
        }
    }
}

struct LidPracticeView: View {
    @State private var questions: [LidQuestion] = LidQuestion.makeExam()
    @State private var scoreText = ""

    var body: some View {
        NavigationView {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    HStack(spacing: 12) {
                        Button("Test auswerten") {
                            evaluate()
                        }
                        .buttonStyle(.borderedProminent)
                        Button("Neue Prüfung") {
                            questions = LidQuestion.makeExam()
                            scoreText = ""
                        }
                        .buttonStyle(.bordered)
                    }

                    if !scoreText.isEmpty {
                        Text(scoreText)
                            .font(.headline)
                    }

                    ForEach($questions) { $question in
                        VStack(alignment: .leading, spacing: 10) {
                            Text(question.question)
                                .font(.headline)
                            ForEach(question.options.indices, id: \.self) { index in
                                Button {
                                    question.selectedIndex = index
                                } label: {
                                    HStack(alignment: .top, spacing: 10) {
                                        Image(systemName: question.selectedIndex == index ? "largecircle.fill.circle" : "circle")
                                        Text(question.options[index])
                                            .foregroundColor(.primary)
                                    }
                                    .frame(maxWidth: .infinity, alignment: .leading)
                                    .padding(12)
                                    .background(Color(.secondarySystemBackground))
                                    .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                                }
                                .buttonStyle(.plain)
                            }
                        }
                        .padding()
                        .background(Color(.systemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: 18, style: .continuous))
                        .overlay(
                            RoundedRectangle(cornerRadius: 18, style: .continuous)
                                .stroke(Color(.separator), lineWidth: 1)
                        )
                    }
                }
                .padding()
            }
            .navigationTitle("LiD Übungstest")
        }
    }

    private func evaluate() {
        let correct = questions.filter { $0.selectedIndex == $0.correctIndex }.count
        scoreText = "Ergebnis: \(correct)/\(questions.count)"
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
                            Text("Restzeit: \(remaining) Sek.")
                        }
                    }
                    if hw.state?.can_start == true {
                        Button("Bearbeitung starten") {
                            Task { await startAssignment() }
                        }
                        .buttonStyle(.bordered)
                    }
                    TextEditor(text: $letterText)
                        .frame(height: 220)
                        .overlay(RoundedRectangle(cornerRadius: 10).stroke(Color.gray.opacity(0.2)))
                    Button("Brief hochladen") {
                        Task { await submit() }
                    }
                    .buttonStyle(.borderedProminent)
                } else {
                    Text(homework?.message ?? "Derzeit keine Aufgabe.")
                        .foregroundColor(.secondary)
                }
                if !status.isEmpty {
                    Text(status).foregroundColor(.secondary)
                }
            }
            .padding()
        }
        .onAppear { Task { await load() } }
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
            VStack(alignment: .leading, spacing: 18) {
                Text("Hausaufgaben")
                    .font(.title2).bold()
                if let homeworks = portal?.homeworks, !homeworks.isEmpty {
                    ForEach(homeworks) { homework in
                        VStack(alignment: .leading, spacing: 6) {
                            Text(homework.title ?? "Aufgabe")
                                .font(.headline)
                            Text(homework.description ?? "")
                                .foregroundColor(.secondary)
                            Text("Status: \(homework.status ?? "-")")
                                .font(.subheadline)
                        }
                        .padding()
                        .background(Color(.secondarySystemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
                    }
                } else {
                    Text("Keine Hausaufgaben vorhanden.")
                        .foregroundColor(.secondary)
                }

                Text("Korrigierte Briefe")
                    .font(.title3).bold()
                if let corrections = portal?.letter_corrections, !corrections.isEmpty {
                    ForEach(corrections) { correction in
                        VStack(alignment: .leading, spacing: 6) {
                            Text(correction.topic ?? "Brief")
                                .font(.headline)
                            if let score = correction.score_total {
                                Text("Punkte: \(score)/20")
                            }
                            if let level = correction.niveau_einschaetzung {
                                Text("Niveau: \(level)")
                            }
                            Text(correction.corrected_text ?? "")
                                .foregroundColor(.secondary)
                        }
                        .padding()
                        .background(Color(.secondarySystemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
                    }
                } else {
                    Text("Noch keine freigegebenen Korrekturen.")
                        .foregroundColor(.secondary)
                }

                Text("Ergebnisse")
                    .font(.title3).bold()
                if let results = portal?.results, !results.isEmpty {
                    ForEach(results) { result in
                        VStack(alignment: .leading, spacing: 4) {
                            Text(result.type ?? "Ergebnis")
                            Text(result.detail ?? "")
                                .foregroundColor(.secondary)
                        }
                        .padding()
                        .background(Color(.secondarySystemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
                    }
                } else {
                    Text("Noch keine Ergebnisse vorhanden.")
                        .foregroundColor(.secondary)
                }

                if !status.isEmpty {
                    Text(status).foregroundColor(.secondary)
                }
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

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Konto")
                .font(.headline)
            Text(session.displayName.isEmpty ? session.username : session.displayName)
                .foregroundColor(.secondary)
            Button("Abmelden") {
                Task { await logout() }
            }
            .buttonStyle(.bordered)
            Spacer()
        }
        .padding()
    }

    private func logout() async {
        _ = try? await APIClient.shared.studentLogout()
        session.clear()
    }
}

struct OptionsView: View {
    let options: [String]
    let key: String
    @Binding var answers: [String: String]

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            ForEach(Array(options.enumerated()), id: \.offset) { idx, option in
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
            ForEach(list, id: \.self) { label in
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
            ForEach(lines, id: \.self) { line in
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
            ForEach(ads.keys.sorted(), id: \.self) { key in
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
            ForEach(options.keys.sorted(), id: \.self) { key in
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

private struct LidQuestion: Identifiable {
    let id = UUID()
    let question: String
    let options: [String]
    let correctIndex: Int
    var selectedIndex: Int? = nil

    static func makeExam() -> [LidQuestion] {
        let general = lidGeneralPool.shuffled().prefix(30)
        let bw = lidBwPool.shuffled().prefix(3)
        return Array(general + bw).map { item in
            LidQuestion(question: item.question, options: item.options, correctIndex: item.correctIndex)
        }
    }
}

private struct LidQuestionSeed {
    let question: String
    let options: [String]
    let correctIndex: Int
}

private struct SpeakingPart2Topic {
    let title: String
    let lead: String
    let scene: String
    let points: [String]
}

private struct SpeakingPart3Topic {
    let task: String
    let plan: String
    let points: [String]
}

private let lidGeneralPool: [LidQuestionSeed] = [
    .init(question: "Wozu dient das Grundgesetz?", options: ["Es regelt die wichtigsten Rechte und die staatliche Ordnung.", "Es ist nur ein Gesetz für Schulen.", "Es gilt nur in Bayern.", "Es regelt nur den Straßenverkehr."], correctIndex: 0),
    .init(question: "Wer wählt den Deutschen Bundestag?", options: ["Die Bürgerinnen und Bürger mit Wahlrecht.", "Nur die Bundesländer.", "Nur die Bundesregierung.", "Nur Parteien ohne Mitglieder."], correctIndex: 0),
    .init(question: "Wie oft findet die Bundestagswahl normalerweise statt?", options: ["Alle 4 Jahre.", "Jedes Jahr.", "Alle 10 Jahre.", "Alle 2 Jahre."], correctIndex: 0),
    .init(question: "Was bedeutet Meinungsfreiheit?", options: ["Man darf seine Meinung frei äußern, solange Gesetze beachtet werden.", "Nur Politiker dürfen sprechen.", "Man darf andere beleidigen.", "Nur im Internet darf man reden."], correctIndex: 0),
    .init(question: "Was ist eine Koalition?", options: ["Zusammenarbeit mehrerer Parteien zur Regierungsbildung.", "Ein Gerichtsurteil.", "Eine Bürgerinitiative ohne Parteien.", "Ein Wahlkreis."], correctIndex: 0),
    .init(question: "Wer kontrolliert in Deutschland die Regierung parlamentarisch?", options: ["Der Bundestag.", "Nur die Polizei.", "Nur die Kirchen.", "Die Bundesbank allein."], correctIndex: 0),
    .init(question: "Was ist das Wahlgeheimnis?", options: ["Niemand darf wissen, wen ich gewählt habe.", "Nur die Familie darf es wissen.", "Der Arbeitgeber muss es wissen.", "Die Stimme wird öffentlich abgegeben."], correctIndex: 0),
    .init(question: "Welche Gewalt spricht Recht?", options: ["Die Judikative.", "Die Legislative.", "Die Exekutive.", "Die Medien."], correctIndex: 0),
    .init(question: "Was bedeutet Religionsfreiheit?", options: ["Jeder darf seinen Glauben wählen oder keinen haben.", "Nur eine Religion ist erlaubt.", "Glaube ist nur privat verboten.", "Nur an Feiertagen ist Religion erlaubt."], correctIndex: 0),
    .init(question: "Was ist ein Bundesland?", options: ["Ein Teilstaat der Bundesrepublik Deutschland.", "Ein Stadtviertel.", "Ein Verein.", "Ein Gericht."], correctIndex: 0),
    .init(question: "Wozu gibt es Parteien?", options: ["Sie vertreten politische Ziele und wirken an der Willensbildung mit.", "Sie ersetzen Gerichte.", "Sie führen Personalausweise aus.", "Sie sind nur für Sport zuständig."], correctIndex: 0),
    .init(question: "Was ist eine Demonstration?", options: ["Öffentliche Versammlung zur Meinungsäußerung.", "Eine geheime Wahl.", "Ein Gerichtsverfahren.", "Ein Schulfach."], correctIndex: 0),
    .init(question: "Was bedeutet Sozialstaat?", options: ["Der Staat hilft Menschen in Not und sichert Grundrisiken ab.", "Der Staat bezahlt allen denselben Lohn.", "Der Staat verbietet Arbeit.", "Der Staat unterstützt nur Unternehmen."], correctIndex: 0),
    .init(question: "Was ist mit 'Rechtsstaat' gemeint?", options: ["Staatliches Handeln ist an Gesetze gebunden.", "Nur Richter machen Politik.", "Gesetze gelten nur für Bürger.", "Polizei entscheidet ohne Gerichte."], correctIndex: 0),
    .init(question: "Wofür steht die Abkürzung EU?", options: ["Europäische Union.", "Europäische Universität.", "Einheitliche Union.", "Europa-Unternehmen."], correctIndex: 0),
    .init(question: "Was ist im Arbeitsleben in Deutschland wichtig?", options: ["Pünktlichkeit und Zuverlässigkeit.", "Verträge ignorieren.", "Regeln selbst erfinden.", "Steuern nicht zahlen."], correctIndex: 0),
    .init(question: "Welche Stelle hilft bei Arbeitslosigkeit oft zuerst?", options: ["Agentur für Arbeit bzw. Jobcenter.", "Das Standesamt.", "Das Museum.", "Die Schule."], correctIndex: 0),
    .init(question: "Was regelt ein Mietvertrag?", options: ["Rechte und Pflichten von Mieter und Vermieter.", "Nur Strompreise.", "Nur Hausordnung ohne Rechte.", "Nur Möbelkauf."], correctIndex: 0),
    .init(question: "Was ist eine Pflicht aller Kinder in Deutschland?", options: ["Schulpflicht.", "Autofahren.", "Parteimitgliedschaft.", "Steuererklärung mit 8 Jahren."], correctIndex: 0),
    .init(question: "Was bedeutet 'Integration'?", options: ["Aktive Teilhabe am gesellschaftlichen Leben unter Achtung der Regeln.", "Nur in der eigenen Gruppe bleiben.", "Gesetze nicht beachten.", "Ohne Deutschkenntnisse Behörden meiden."], correctIndex: 0),
    .init(question: "Was ist in Deutschland verboten?", options: ["Diskriminierung wegen Herkunft oder Religion.", "Ehrenamtliche Arbeit.", "Meinungsfreiheit.", "Wählen."], correctIndex: 0),
    .init(question: "Wofür zahlt man in Deutschland Steuern?", options: ["Zur Finanzierung öffentlicher Aufgaben wie Schulen und Straßen.", "Nur für private Vereine.", "Nur für Parteien.", "Nur für Sportvereine."], correctIndex: 0),
    .init(question: "Was ist typisch für eine Demokratie?", options: ["Freie Wahlen und mehrere Parteien.", "Nur eine Partei.", "Keine Kritik erlaubt.", "Wahlen ohne Geheimnis."], correctIndex: 0),
    .init(question: "Was schützt das Allgemeine Gleichbehandlungsgesetz (AGG)?", options: ["Vor Benachteiligung, z. B. wegen Herkunft, Religion oder Geschlecht.", "Vor Steuerpflicht.", "Vor Schulpflicht.", "Vor Wahlen."], correctIndex: 0),
    .init(question: "Was ist Ehrenamt?", options: ["Freiwilliges Engagement für andere ohne normalen Arbeitslohn.", "Pflichtdienst im Unternehmen.", "Bezahlte Vollzeitstelle.", "Nur Tätigkeit in Parteien."], correctIndex: 0),
    .init(question: "Warum sind Deutschkenntnisse für den Alltag wichtig?", options: ["Für Arbeit, Schule, Behörden und gesellschaftliche Teilhabe.", "Nur für den Urlaub.", "Nur für Fernsehen.", "Nur für den Führerschein."], correctIndex: 0),
    .init(question: "Was bedeutet Gewaltenteilung?", options: ["Legislative, Exekutive und Judikative kontrollieren sich gegenseitig.", "Alle Macht bei einer Behörde.", "Nur Gerichte machen Gesetze.", "Nur Regierung kontrolliert Gerichte."], correctIndex: 0),
    .init(question: "Was ist die Aufgabe von Gerichten?", options: ["Streitfälle nach dem Gesetz entscheiden.", "Wahlen organisieren.", "Steuern eintreiben.", "Schulen bauen."], correctIndex: 0),
    .init(question: "Ab welchem Alter darf man bei Bundestagswahlen wählen?", options: ["Ab 18 Jahren.", "Ab 14 Jahren.", "Ab 16 Jahren.", "Ab 21 Jahren."], correctIndex: 0),
    .init(question: "Welche Institution beschließt Bundesgesetze hauptsächlich?", options: ["Der Bundestag.", "Das Rathaus.", "Die Polizei.", "Der Bundespräsident allein."], correctIndex: 0)
]

private let lidBwPool: [LidQuestionSeed] = [
    .init(question: "Wie heißt die Landeshauptstadt von Baden-Württemberg?", options: ["Stuttgart.", "Mannheim.", "Karlsruhe.", "Freiburg."], correctIndex: 0),
    .init(question: "Wie heißt das Landesparlament von Baden-Württemberg?", options: ["Landtag von Baden-Württemberg.", "Bundestag.", "Bundesrat.", "Senat."], correctIndex: 0),
    .init(question: "Welche Farben hat die Landesflagge von Baden-Württemberg?", options: ["Schwarz und Gold.", "Rot und Weiß.", "Blau und Gelb.", "Grün und Weiß."], correctIndex: 0),
    .init(question: "In welcher Stadt hat der Verfassungsgerichtshof von Baden-Württemberg seinen Sitz?", options: ["Stuttgart.", "Ulm.", "Heidelberg.", "Konstanz."], correctIndex: 0),
    .init(question: "Welcher Fluss fließt durch Baden-Württemberg?", options: ["Neckar.", "Ems.", "Saale.", "Werra."], correctIndex: 0)
]

private let speakingPart2Topics: [SpeakingPart2Topic] = [
    .init(title: "Bild: Im Supermarkt", lead: "Beschreiben Sie das Bild. Sprechen Sie danach über eigene Einkaufserfahrungen.", scene: "supermarkt", points: ["Wo sind die Personen?", "Was machen die Personen?", "Wie wirkt die Situation?", "Wie ist das bei Ihnen?"]),
    .init(title: "Bild: In der Arztpraxis", lead: "Beschreiben Sie das Bild. Erzählen Sie dann von einem Arztbesuch.", scene: "arzt", points: ["Was sehen Sie im Raum?", "Was machen die Personen?", "Wie fühlen sich die Personen?", "Welche Erfahrung haben Sie?"]),
    .init(title: "Bild: Im Sprachkurs", lead: "Beschreiben Sie das Bild. Sprechen Sie danach über Lernen im Kurs.", scene: "kurs", points: ["Wo findet die Situation statt?", "Was machen Lehrkraft und Teilnehmende?", "Welche Materialien sehen Sie?", "Wie lernen Sie am besten?"]),
    .init(title: "Bild: Am Bahnhof", lead: "Beschreiben Sie das Bild. Sprechen Sie dann über Mobilität im Alltag.", scene: "bahnhof", points: ["Wo sind die Personen?", "Was passiert gerade?", "Welche Probleme können entstehen?", "Wie fahren Sie normalerweise?"]),
    .init(title: "Bild: Im Büro", lead: "Beschreiben Sie das Bild. Sprechen Sie danach über Ihren Arbeitsalltag.", scene: "buero", points: ["Wo sind die Personen?", "Was arbeiten sie?", "Welche Technik sehen Sie?", "Wie sieht Ihr Arbeitstag aus?"]),
    .init(title: "Bild: Im Park", lead: "Beschreiben Sie das Bild. Sprechen Sie danach über Freizeit und Bewegung.", scene: "park", points: ["Welche Aktivitäten sehen Sie?", "Wie ist das Wetter?", "Welche Personen sind da?", "Was machen Sie gern draußen?"])
]

private let speakingPart3Topics: [SpeakingPart3Topic] = [
    .init(task: "eine Wochenendreise machen und in den Bergen wandern", plan: "die Reise", points: ["Anreise", "Unterkunft", "Wanderroute", "Essen/Trinken", "Kosten"]),
    .init(task: "einen Tagesausflug an den See machen", plan: "den Ausflug", points: ["Treffpunkt", "Abfahrt", "Was mitnehmen?", "Programm vor Ort", "Rückfahrt"]),
    .init(task: "ein Nachbarschaftstreffen organisieren", plan: "das Treffen", points: ["Ort", "Datum/Uhrzeit", "Einladungen", "Essen/Getränke", "Aufgabenverteilung"]),
    .init(task: "eine kleine Feier nach dem Kurs planen", plan: "die Feier", points: ["Termin", "Ort", "Musik", "Essen/Getränke", "Budget"]),
    .init(task: "einen gemeinsamen Lerntag für die Prüfung vorbereiten", plan: "den Lerntag", points: ["Themen", "Zeiten", "Material", "Pausen", "Lernort"]),
    .init(task: "einen gemeinsamen Kochabend mit Freunden organisieren", plan: "den Kochabend", points: ["Menü", "Einkauf", "Aufgaben", "Beginn", "Kostenaufteilung"])
]

extension Array {
    subscript(safe index: Int) -> Element? {
        guard indices.contains(index) else { return nil }
        return self[index]
    }
}
