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

    func memberSession() async throws -> MemberSession {
        let data = try await request(path: "api/member_session.php")
        return try JSONDecoder().decode(MemberSession.self, from: data)
    }

    func register(username: String, password: String, displayName: String, email: String) async throws -> APIBasicResponse {
        let payload = ["username": username, "password": password, "display_name": displayName, "email": email]
        let data = try await request(path: "api/member_register.php", method: "POST", body: try JSONSerialization.data(withJSONObject: payload))
        return try JSONDecoder().decode(APIBasicResponse.self, from: data)
    }

    func login(username: String, password: String) async throws -> MemberSession {
        let payload = ["username": username, "password": password]
        let data = try await request(path: "api/member_login.php", method: "POST", body: try JSONSerialization.data(withJSONObject: payload))
        return try JSONDecoder().decode(MemberSession.self, from: data)
    }

    func logout() async throws {
        _ = try await request(path: "api/member_logout.php", method: "POST", body: Data("{}".utf8))
    }

    func saveLetter(name: String, letter: String, prompt: String, points: [String]) async throws -> APIBasicResponse {
        let payload: [String: Any] = [
            "student_name": name,
            "letter_text": letter,
            "task_prompt": prompt,
            "required_points": points
        ]
        let data = try await request(path: "api/member_save_letter.php", method: "POST", body: try JSONSerialization.data(withJSONObject: payload))
        return try JSONDecoder().decode(APIBasicResponse.self, from: data)
    }

    func portal() async throws -> MemberPortalResponse {
        let data = try await request(path: "api/member_portal.php")
        return try JSONDecoder().decode(MemberPortalResponse.self, from: data)
    }

    func updateProfile(displayName: String, email: String, currentPassword: String, newPassword: String) async throws -> APIBasicResponse {
        let payload = [
            "display_name": displayName,
            "email": email,
            "current_password": currentPassword,
            "new_password": newPassword
        ]
        let data = try await request(path: "api/member_update.php", method: "POST", body: try JSONSerialization.data(withJSONObject: payload))
        return try JSONDecoder().decode(APIBasicResponse.self, from: data)
    }
}

enum APIError: Error {
    case server(String)
}

struct APIBasicResponse: Codable {
    let ok: Bool
    let error: String?
}
