import SwiftUI
import WebKit

struct RootView: View {
    @EnvironmentObject var session: MemberSessionStore
    @State private var loading = true
    @State private var showInternal = false

    var body: some View {
        NavigationView {
            if loading {
                ProgressView("Laden...")
                    .onAppear {
                        Task {
                            if let s = try? await APIClient.shared.memberSession() {
                                session.apply(s)
                            }
                            loading = false
                        }
                    }
            } else {
                if showInternal {
                    InternalWebView(url: URL(string: "https://dtz-lid.com/index.html#internArea")!)
                        .ignoresSafeArea()
                } else {
                    WelcomeView(showInternal: $showInternal)
                }
            }
        }
        .navigationViewStyle(StackNavigationViewStyle())
    }
}

struct WelcomeView: View {
    @EnvironmentObject var session: MemberSessionStore
    @Binding var showInternal: Bool

    var body: some View {
        ScrollView {
            VStack(spacing: 16) {
                Text("DTZ-LiD")
                    .font(.largeTitle).bold()
                Text("Bitte wählen Sie den Bereich")
                    .foregroundColor(.secondary)
                NavigationLink(destination: MemberHomeView()) {
                    Text("Mitgliedsbereich")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.borderedProminent)
                Button("Intern (Kurs/Lehrkraft)") { showInternal = true }
                    .buttonStyle(.bordered)
            }
            .padding()
        }
    }
}

struct MemberHomeView: View {
    @EnvironmentObject var session: MemberSessionStore

    var body: some View {
        if session.authenticated {
            MemberPortalView()
        } else {
            MemberAuthView()
        }
    }
}

struct MemberAuthView: View {
    @EnvironmentObject var session: MemberSessionStore
    @State private var regUser = ""
    @State private var regPass = ""
    @State private var regName = ""
    @State private var regEmail = ""
    @State private var loginUser = ""
    @State private var loginPass = ""
    @State private var status = ""

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                Text("Registrierung")
                    .font(.headline)
                Group {
                    TextField("Benutzername", text: $regUser)
                    SecureField("Passwort", text: $regPass)
                    TextField("Name (optional)", text: $regName)
                    TextField("E-Mail (optional)", text: $regEmail)
                }
                .textFieldStyle(.roundedBorder)
                Button("Jetzt registrieren") {
                    Task { await doRegister() }
                }
                .buttonStyle(.borderedProminent)

                Divider()
                Text("Login")
                    .font(.headline)
                Group {
                    TextField("Benutzername", text: $loginUser)
                    SecureField("Passwort", text: $loginPass)
                }
                .textFieldStyle(.roundedBorder)
                Button("Anmelden") {
                    Task { await doLogin() }
                }
                .buttonStyle(.bordered)

                if !status.isEmpty {
                    Text(status).foregroundColor(.secondary)
                }
            }
            .padding()
        }
    }

    private func doRegister() async {
        do {
            let resp = try await APIClient.shared.register(username: regUser, password: regPass, displayName: regName, email: regEmail)
            status = resp.ok ? "Registriert. Bitte anmelden." : (resp.error ?? "Fehler")
        } catch {
            status = "Fehler bei Registrierung"
        }
    }

    private func doLogin() async {
        do {
            let s = try await APIClient.shared.login(username: loginUser, password: loginPass)
            session.apply(s)
        } catch {
            status = "Login fehlgeschlagen"
        }
    }
}

struct MemberPortalView: View {
    @EnvironmentObject var session: MemberSessionStore
    @State private var selection = 0

    var body: some View {
        TabView(selection: $selection) {
            MemberMailView().tabItem { Label("Schreiben", systemImage: "pencil") }.tag(0)
            CorrectionsView().tabItem { Label("Korrekturen", systemImage: "checkmark.seal") }.tag(1)
            ProfileView().tabItem { Label("Meine Daten", systemImage: "person") }.tag(2)
        }
    }
}

struct MemberMailView: View {
    @State private var selected = 0
    @State private var text = ""
    @State private var status = ""

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Picker("Thema", selection: $selected) {
                    ForEach(0..<memberTopics.count, id: \.self) { idx in
                        Text(memberTopics[idx].title).tag(idx)
                    }
                }
                .pickerStyle(.menu)
                Text(memberTopics[selected].prompt)
                    .foregroundColor(.secondary)
                TextEditor(text: $text)
                    .frame(height: 180)
                    .overlay(RoundedRectangle(cornerRadius: 8).stroke(Color.gray.opacity(0.2)))
                Button("Brief hochladen") {
                    Task { await send() }
                }
                .buttonStyle(.borderedProminent)
                if !status.isEmpty { Text(status).foregroundColor(.secondary) }
            }
            .padding()
        }
    }

    private func send() async {
        do {
            let topic = memberTopics[selected]
            _ = try await APIClient.shared.saveLetter(name: "", letter: text, prompt: topic.prompt, points: topic.points)
            status = "Brief hochgeladen. Freigabe ausstehend."
            text = ""
        } catch {
            status = "Upload fehlgeschlagen"
        }
    }
}

struct CorrectionsView: View {
    @State private var corrections: [MemberCorrection] = []
    @State private var selected = 0

    var body: some View {
        VStack {
            if corrections.isEmpty {
                Text("Noch keine freigegebenen Korrekturen")
                    .foregroundColor(.secondary)
            } else {
                Picker("Korrektur", selection: $selected) {
                    ForEach(0..<corrections.count, id: \.self) { idx in
                        Text(corrections[idx].topic ?? "Korrektur")
                    }
                }
                .pickerStyle(.menu)
                ScrollView {
                    Text(corrections[selected].corrected_text ?? "")
                        .padding()
                }
            }
        }
        .onAppear {
            Task {
                if let data = try? await APIClient.shared.portal() {
                    corrections = data.corrections
                }
            }
        }
    }
}

struct ProfileView: View {
    @EnvironmentObject var session: MemberSessionStore
    @State private var name = ""
    @State private var email = ""
    @State private var currentPassword = ""
    @State private var newPassword = ""
    @State private var status = ""

    var body: some View {
        Form {
            Section(header: Text("Profil")) {
                TextField("Name", text: $name)
                TextField("E-Mail", text: $email)
            }
            Section(header: Text("Passwort ändern")) {
                SecureField("Aktuelles Passwort", text: $currentPassword)
                SecureField("Neues Passwort", text: $newPassword)
            }
            Button("Änderungen speichern") {
                Task { await save() }
            }
            Button("Abmelden") {
                Task { await logout() }
            }
            if !status.isEmpty { Text(status) }
        }
        .onAppear {
            name = session.displayName
            email = session.email
        }
    }

    private func save() async {
        do {
            _ = try await APIClient.shared.updateProfile(displayName: name, email: email, currentPassword: currentPassword, newPassword: newPassword)
            status = "Gespeichert"
            currentPassword = ""
            newPassword = ""
        } catch {
            status = "Fehler beim Speichern"
        }
    }

    private func logout() async {
        try? await APIClient.shared.logout()
        session.clear()
    }
}

struct InternalWebView: UIViewRepresentable {
    let url: URL
    func makeUIView(context: Context) -> WKWebView {
        WKWebView()
    }
    func updateUIView(_ uiView: WKWebView, context: Context) {
        uiView.load(URLRequest(url: url))
    }
}
