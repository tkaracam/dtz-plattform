# DTZ-LiD Native Apps

Bu klasör iki **tam native** uygulama için başlangıç iskeletlerini içerir.

## iOS (SwiftUI)
Kaynaklar: `native/ios/App/`

Önerilen kurulum:
1. Xcode → New Project → App (SwiftUI)
2. Bundle ID: `com.dtzlid.app`
3. Oluşan projede `ContentView.swift` yerine `RootView.swift` kullanın.
4. `DTZLiDApp.swift`, `APIClient.swift`, `Models.swift`, `RootView.swift` dosyalarını projeye ekleyin.
5. Asset olarak `assets/dtzlib-logo-v3.png` ekleyin (Images.xcassets).

## Android (Kotlin + Compose)
Kaynaklar: `native/android/App/src/main/java/com/dtzlid/app/MainActivity.kt`

Önerilen kurulum:
1. Android Studio → New Project → Empty Compose Activity
2. Package name: `com.dtzlid.app`
3. `MainActivity.kt` dosyasını bu içerikle değiştirin.
4. build.gradle’a OkHttp ekleyin:
   implementation "com.squareup.okhttp3:okhttp:4.12.0"

## API uçları
Üyelik:
- `api/member_register.php`
- `api/member_login.php`
- `api/member_session.php`
- `api/member_logout.php`
- `api/member_update.php`
- `api/member_save_letter.php`
- `api/member_portal.php`

İç (mevcut sistem):
- `https://dtz-lid.com/index.html#internArea` WebView ile açılıyor.
