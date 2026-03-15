import Foundation

final class APIClient {
    static let shared = APIClient()

    private let baseURL = URL(string: "https://dtz-lid.com")!
    private let session: URLSession

    init() {
        let config = URLSessionConfiguration.default
        config.httpCookieStorage = HTTPCookieStorage.shared
        config.httpShouldSetCookies = true
        config.requestCachePolicy = .reloadIgnoringLocalCacheData
        self.session = URLSession(configuration: config)
    }

    private func request(path: String, method: String = "GET", body: Data? = nil) async throws -> Data {
        let url = baseURL.appendingPathComponent(path)
        var req = URLRequest(url: url)
        req.httpMethod = method
        if let body = body {
            req.httpBody = body
            req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        }
        let (data, response) = try await session.data(for: req)
        if let http = response as? HTTPURLResponse, http.statusCode >= 400 {
            throw APIError.server(String(data: data, encoding: .utf8) ?? "Serverfehler")
        }
        return data
    }

    func studentSession() async throws -> StudentSession {
        let data = try await request(path: "api/student_session.php")
        return try JSONDecoder().decode(StudentSession.self, from: data)
    }

    func studentLogin(username: String, password: String) async throws -> StudentSession {
        let payload = ["username": username, "password": password]
        let data = try await request(path: "api/student_login.php", method: "POST", body: try JSONSerialization.data(withJSONObject: payload))
        return try JSONDecoder().decode(StudentSession.self, from: data)
    }

    func studentLogout() async throws -> APIBasicResponse {
        let data = try await request(path: "api/student_logout.php", method: "POST", body: Data("{}".utf8))
        return try JSONDecoder().decode(APIBasicResponse.self, from: data)
    }

    func trainingSet(module: String, teil: Int) async throws -> TrainingSetResponse {
        let payload: [String: Any] = ["module": module, "teil": teil]
        let data = try await request(path: "api/student_training_set.php", method: "POST", body: try JSONSerialization.data(withJSONObject: payload))
        return try JSONDecoder().decode(TrainingSetResponse.self, from: data)
    }

    func currentHomework() async throws -> HomeworkCurrentResponse {
        let data = try await request(path: "api/student_homework_current.php")
        return try JSONDecoder().decode(HomeworkCurrentResponse.self, from: data)
    }

    func startHomework(assignmentId: String) async throws -> HomeworkStartResponse {
        let payload = ["assignment_id": assignmentId]
        let data = try await request(path: "api/student_homework_start.php", method: "POST", body: try JSONSerialization.data(withJSONObject: payload))
        return try JSONDecoder().decode(HomeworkStartResponse.self, from: data)
    }

    func submitLetter(assignmentId: String, prompt: String, text: String, startedAt: String?, durationSeconds: Int) async throws -> APIBasicResponse {
        let payload: [String: Any] = [
            "assignment_id": assignmentId,
            "task_prompt": prompt,
            "letter_text": text,
            "student_name": "",
            "required_points": [],
            "writing_started_at": startedAt ?? "",
            "writing_duration_seconds": durationSeconds,
            "auto_submit_on_expiry": true
        ]
        let data = try await request(path: "api/save_letter.php", method: "POST", body: try JSONSerialization.data(withJSONObject: payload))
        return try JSONDecoder().decode(APIBasicResponse.self, from: data)
    }

    func studentPortal() async throws -> StudentPortalResponse {
        let data = try await request(path: "api/student_portal.php")
        return try JSONDecoder().decode(StudentPortalResponse.self, from: data)
    }
}

enum APIError: Error {
    case server(String)
}
