# Otomatik Test Planı (DTZ-LiD)

## Hedef
Deploy riskini düşürmek, kritik öğrenci/dozent akışlarını her değişiklikte otomatik doğrulamak.

## Test Katmanları

## 1) Lint / Static
- PHP: tüm `api/*.php` dosyalarında `php -l`.
- HTML/JS: temel grep tabanlı integrity kontrolleri (kritik id/selector varlığı).

## 2) API Smoke
Kritik endpointlerin en az bir başarılı ve bir hata senaryosu:
- `POST /api/admin_login.php`
- `POST /api/homework_assign.php` (`create`, `create_batch`, `delete`, `delete_group`, `dtz_usage_report`)
- `POST /api/student_homework_complete.php`
- `POST /api/student_homework_result.php`
- `POST /api/student_modelltest_result.php`
- `GET /api/student_portal.php`

Not: Auth gerektiren çağrılar için CI secret/env ile test kullanıcısı gerekir.

## 3) E2E (Playwright/Cypress)
Minimum kritik senaryolar:
- Dozent login -> kurs oluştur -> öğrenci üret -> ödev ata.
- Schüler login -> portalda ödev gör -> çözüp `Abgeben`.
- Atanan DTZ Teil ödevinde yasak butonların görünmemesi.
- Atanan Modelltest akışında aşama geçiş kuralları.
- A1/A2/B1 assignment akışlarının in-app çalışması.

## 4) Post-Deploy Smoke
Deploy sonrası hızlı sağlık kontrolü:
- Ana sayfa yanıt veriyor.
- Login endpoint yanıt veriyor.
- Portal endpoint auth davranışı doğru.
- En az 1 kritik UI selector mevcut.

## Önerilen Pipeline Sırası
1. `lint-php`
2. `static-check`
3. `api-smoke`
4. `e2e-critical`
5. `deploy-gate`

## Başarı Kriteri
- Kritik testler: %100 pass
- Majör testler: >= %95 pass
- Fail durumunda deploy bloklanır.

## Bu Repodaki İlk Uygulama
- `tools/ci_smoke.sh` ile:
  - PHP lint
  - temel endpoint health
  - opsiyonel auth smoke (env verilirse)

### Çalıştırma
```bash
BASE_URL="https://dtz-lid.com" tools/ci_smoke.sh
```

Auth smoke dahil:
```bash
BASE_URL="https://dtz-lid.com" \
ADMIN_USER="..." ADMIN_PASS="..." \
STUDENT_USER="..." STUDENT_PASS="..." \
tools/ci_smoke.sh
```
