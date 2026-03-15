import Foundation

struct APIBasicResponse: Codable {
    let ok: Bool?
    let error: String?
}

struct StudentSession: Codable {
    let authenticated: Bool?
    let role: String?
    let role_key: String?
    let username: String?
    let display_name: String?
}

final class StudentSessionStore: ObservableObject {
    @Published var authenticated = false
    @Published var username = ""
    @Published var displayName = ""

    func apply(_ session: StudentSession) {
        authenticated = session.authenticated ?? false
        username = session.username ?? ""
        displayName = session.display_name ?? ""
    }

    func clear() {
        authenticated = false
        username = ""
        displayName = ""
    }
}

struct TrainingSetResponse: Codable {
    let ok: Bool?
    let set: TrainingSet?
    let error: String?
}

struct TrainingSet: Codable {
    let module: String?
    let teil: Int?
    let items: [TrainingItem]
}

struct TrainingItem: Codable, Identifiable {
    var id: String { template_id ?? UUID().uuidString }
    let template_id: String?
    let dtz_schema: String?
    let dtz_part: String?
    let title: String?
    let instructions: String?

    // Common fields
    let audio_script: String?
    let text: String?

    // Hören Teil 1/2
    let questions: [TrainingQuestion]?

    // Hören Teil 3
    let dialogs: [TrainingDialog]?

    // Hören Teil 4
    let options: [String: String]?
    let statements: [TrainingStatement]?
    let allow_reuse: Bool?

    // Lesen Teil 1
    let wegweiser_title: String?
    let wegweiser: [String]?
    let situations: [TrainingSituation]?

    // Lesen Teil 2
    let ads: [String: String]?
    let labels: [String]?

    // Lesen Teil 3
    let blocks: [TrainingBlock]?

    // Lesen Teil 5
    let text_template: String?
    let example: TrainingClozeExample?
    let gaps: [TrainingGap]?
}

struct TrainingQuestion: Codable, Identifiable {
    var id: String { rawId ?? UUID().uuidString }
    private let rawId: String?
    let question: String?
    let options: [String]?
    let correct: String?
    let audio_script: String?
    let explanation: String?

    enum CodingKeys: String, CodingKey {
        case rawId = "id"
        case question, options, correct, audio_script, explanation
    }
}

struct TrainingDialog: Codable, Identifiable {
    var id: String { rawId ?? UUID().uuidString }
    private let rawId: String?
    let title: String?
    let audio_script: String?
    let true_false: TrainingTrueFalse?
    let detail: TrainingDetailQuestion?

    enum CodingKeys: String, CodingKey {
        case rawId = "id"
        case title, audio_script, true_false, detail
    }
}

struct TrainingTrueFalse: Codable {
    let statement: String?
    let correct: String?
    let explanation: String?
}

struct TrainingDetailQuestion: Codable {
    let question: String?
    let options: [String]?
    let correct: String?
    let explanation: String?
}

struct TrainingStatement: Codable, Identifiable {
    var id: String { rawId ?? UUID().uuidString }
    private let rawId: String?
    let title: String?
    let statement: String?
    let question: String?
    let audio_script: String?
    let correct: String?
    let explanation: String?
    let no: Int?

    enum CodingKeys: String, CodingKey {
        case rawId = "id"
        case title, statement, question, audio_script, correct, explanation, no
    }
}

struct TrainingSituation: Codable, Identifiable {
    var id: String { rawId ?? UUID().uuidString }
    private let rawId: String?
    let no: Int?
    let prompt: String?
    let options: [String]?
    let correct: String?
    let explanation: String?

    enum CodingKeys: String, CodingKey {
        case rawId = "id"
        case no, prompt, options, correct, explanation
    }
}

struct TrainingBlock: Codable, Identifiable {
    var id: String { rawId ?? UUID().uuidString }
    private let rawId: String?
    let title: String?
    let text: String?
    let true_false: TrainingBlockTrueFalse?
    let mc: TrainingBlockMC?

    enum CodingKeys: String, CodingKey {
        case rawId = "id"
        case title, text, true_false, mc
    }
}

struct TrainingBlockTrueFalse: Codable {
    let no: Int?
    let statement: String?
    let correct: String?
    let explanation: String?
}

struct TrainingBlockMC: Codable {
    let no: Int?
    let question: String?
    let options: [String]?
    let correct: String?
    let explanation: String?
}

struct TrainingClozeExample: Codable {
    let no: Int?
    let options: [String]?
    let correct: String?
    let explanation: String?
}

struct TrainingGap: Codable, Identifiable {
    var id: String { rawId ?? UUID().uuidString }
    private let rawId: String?
    let no: Int?
    let options: [String]?
    let correct: String?
    let explanation: String?

    enum CodingKeys: String, CodingKey {
        case rawId = "id"
        case no, options, correct, explanation
    }
}

struct HomeworkCurrentResponse: Codable {
    let has_assignment: Bool?
    let message: String?
    let assignment: HomeworkAssignment?
    let state: HomeworkState?
}

struct HomeworkAssignment: Codable {
    let id: String?
    let title: String?
    let description: String?
    let attachment: String?
    let duration_minutes: Int?
    let starts_at: String?
    let status: String?
    let created_at: String?
}

struct HomeworkState: Codable {
    let started_at: String?
    let deadline_at: String?
    let submitted_at: String?
    let remaining_seconds: Int?
    let expired: Bool?
    let locked: Bool?
    let not_active_yet: Bool?
    let planned_only: Bool?
    let can_start: Bool?
    let can_submit: Bool?
    let reminder_label: String?
}

struct HomeworkStartResponse: Codable {
    let ok: Bool?
    let assignment_id: String?
    let started_at: String?
    let deadline_at: String?
    let remaining_seconds: Int?
    let error: String?
}

struct StudentPortalResponse: Codable {
    let results: [PortalResult]?
    let homeworks: [PortalHomework]?
    let latest_letter_correction: LetterCorrection?
    let letter_corrections: [LetterCorrection]?
}

struct PortalResult: Codable, Identifiable {
    var id: String { (created_at ?? "") + (type ?? "") }
    let type: String?
    let created_at: String?
    let score_label: String?
    let percent: Int?
    let detail: String?
}

struct PortalHomework: Codable, Identifiable {
    var id: String { rawId ?? UUID().uuidString }
    private let rawId: String?
    let title: String?
    let description: String?
    let duration_minutes: Int?
    let due_date: String?
    let status: String?

    enum CodingKeys: String, CodingKey {
        case rawId = "id"
        case title, description, duration_minutes, due_date, status
    }
}

struct LetterCorrection: Codable, Identifiable {
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
