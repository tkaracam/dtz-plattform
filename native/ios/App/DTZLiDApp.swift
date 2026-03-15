import SwiftUI

@main
struct DTZLiDApp: App {
    @StateObject private var session = MemberSessionStore()

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(session)
        }
    }
}
