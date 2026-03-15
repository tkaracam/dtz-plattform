import Foundation

struct MemberSession: Codable {
    let authenticated: Bool?
    let ok: Bool?
    let role: String?
    let username: String?
    let display_name: String?
    let email: String?
    let error: String?
}

struct MemberPortalResponse: Codable {
    let corrections: [MemberCorrection]
    let latest: MemberCorrection?
}

struct MemberCorrection: Codable, Identifiable {
    var id: String { upload_id ?? UUID().uuidString }
    let upload_id: String?
    let created_at: String?
    let topic: String?
    let score_total: Int?
    let niveau_einschaetzung: String?
    let corrected_text: String?
    let covered_points: [String]?
    let missing_points: [String]?
}

final class MemberSessionStore: ObservableObject {
    @Published var authenticated = false
    @Published var username = ""
    @Published var displayName = ""
    @Published var email = ""

    func apply(_ session: MemberSession) {
        authenticated = session.authenticated ?? (session.ok ?? false)
        username = session.username ?? ""
        displayName = session.display_name ?? ""
        email = session.email ?? ""
    }

    func clear() {
        authenticated = false
        username = ""
        displayName = ""
        email = ""
    }
}

struct TopicItem: Identifiable {
    let id = UUID()
    let title: String
    let prompt: String
    let points: [String]
}

let memberTopics: [TopicItem] = [
    .init(title: "Arzttermin verschieben", prompt: "Sie haben einen Termin beim Arzt, können aber nicht kommen. Schreiben Sie eine E-Mail: Grund, neuer Terminwunsch, Kontakt.", points: ["Grund nennen", "Neuen Termin vorschlagen", "Kontaktmöglichkeit nennen"]),
    .init(title: "Wohnung: Heizung defekt", prompt: "Schreiben Sie an die Hausverwaltung: Heizung ist kaputt. Nennen Sie seit wann und bitten Sie um Reparatur.", points: ["Problem beschreiben", "Seit wann", "Um Reparatur bitten"]),
    .init(title: "Kurs: Fehlende Kursbescheinigung", prompt: "Bitten Sie Ihre Sprachschule um eine Kursbescheinigung. Nennen Sie Kurs und Grund.", points: ["Kurs nennen", "Grund nennen", "Um Bescheinigung bitten"]),
    .init(title: "Arbeit: Urlaub beantragen", prompt: "Schreiben Sie Ihrer Chefin: Wunschdatum, Grund, Bitte um Bestätigung.", points: ["Wunschdatum", "Grund", "Bestätigung erbitten"]),
    .init(title: "Behörde: Termin absagen", prompt: "Sagen Sie einen Termin beim Bürgeramt ab und bitten Sie um einen neuen Termin.", points: ["Termin absagen", "Grund", "Neuer Terminwunsch"])
]
